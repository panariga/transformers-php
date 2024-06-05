<?php

declare(strict_types=1);


namespace Codewithkyrian\Transformers\Models\Pretrained;

use Codewithkyrian\Transformers\Generation\LogitsProcessors\LogitsProcessorList;
use Codewithkyrian\Transformers\Generation\LogitsProcessors\WhisperTimeStampLogitsProcessor;
use Codewithkyrian\Transformers\Generation\Streamers\Streamer;
use Codewithkyrian\Transformers\Models\ModelArchitecture;
use Codewithkyrian\Transformers\OnnxRuntime\InferenceSession;
use Codewithkyrian\Transformers\Tensor\Tensor;
use Codewithkyrian\Transformers\Utils\AutoConfig;
use Codewithkyrian\Transformers\Utils\GenerationConfig;
use Exception;
use InvalidArgumentException;

class WhisperForConditionalGeneration extends WhisperPretrainedModel
{
    public bool $requiresAttentionMask = false;
    public string $mainInputName = 'input_features';

    protected mixed $numDecoderLayers;
    protected mixed $numDecoderHeads;
    protected mixed $decoderDimKv;
    protected mixed $numEncoderLayers;
    protected mixed $numEncoderHeads;
    protected mixed $encoderDimKv;

    public function __construct(
        AutoConfig               $config,
        InferenceSession         $session,
        public InferenceSession  $decoderMergedSession,
        public ModelArchitecture $modelArchitecture,
        public GenerationConfig  $generationConfig
    )
    {
        parent::__construct($config, $session, $modelArchitecture);

        $this->numDecoderLayers = $this->config['decoder_layers'];
        $this->numDecoderHeads = $this->config['decoder_attention_heads'];
        $this->decoderDimKv = $this->config['d_model'] / $this->numDecoderHeads;

        $this->numEncoderLayers = $this->config['encoder_layers'];
        $this->numEncoderHeads = $this->config['encoder_attention_heads'];
        $this->encoderDimKv = $this->config['d_model'] / $this->numEncoderHeads;
    }

    public function generate(
        Tensor               $inputs,
        ?GenerationConfig    $generationConfig = null,
        ?LogitsProcessorList $logitsProcessor = null,
        Tensor               $inputsAttentionMask = null,
        ?Streamer            $streamer = null
    ): array
    {
        $generationConfig = $this->getGenerationConfig($generationConfig);

        // Whisper has additional options for returning timestamps
        $generationConfig['return_timestamps'] ??= false;


        if ($generationConfig['return_timestamps']) {
            $logitsProcessor = new LogitsProcessorList();
            $logitsProcessor->push(new WhisperTimeStampLogitsProcessor($generationConfig));
        }


        if (isset($generationConfig['return_token_timestamps'])) {
            $generationConfig['output_attentions'] = true;
            $generationConfig['return_dict_in_generate'] = true;

            if ($generationConfig['task'] ?? '' === 'translate') {
                trigger_error("Token-level timestamps may not be reliable for task 'translate'.", E_USER_WARNING);
            }

            if (!isset($generationConfig['alignment_heads'])) {
                throw new Exception(
                    "Model generation config has no `alignment_heads`, token-level timestamps not available. " .
                    "See https://gist.github.com/hollance/42e32852f24243b748ae6bc1f985b13a on how to add this property to the generation config."
                );
            }
        }


        $outputs = parent::generate($inputs, $generationConfig, $logitsProcessor, $inputsAttentionMask, $streamer);

        if (isset($generationConfig['return_token_timestamps']) && isset($generationConfig['alignment_heads'])) {
            $outputs['token_timestamps'] = $this->extractTokenTimestamps(
                $outputs,
                $generationConfig['alignment_heads'],
                (int)$generationConfig['num_frames'] ?? null,
            );
        }

        return $outputs;
    }

    /**
     * Calculates token-level timestamps using the encoder-decoder cross-attentions and
     * dynamic time-warping (DTW) to map each output token to a position in the input audio.
     *
     * @param array $generateOutputs Outputs generated by the model
     * @param array $alignmentHeads Alignment heads of the model
     * @param int|null $numFrames Number of frames in the input audio
     * @param float $timePrecision Precision of the timestamps in seconds
     * @return Tensor Tensor containing the timestamps in seconds for each predicted token
     * @throws Exception If the model outputs do not contain cross attentions
     */
    public function extractTokenTimestamps(
        array          $generateOutputs,
        array          $alignmentHeads,
        int|null $numFrames = null,
        float          $timePrecision = 0.02
    ): Tensor
    {
        if (!isset($generateOutputs['cross_attentions'])) {
            throw new Exception(
                "Model outputs must contain cross attentions to extract timestamps. " .
                "This is most likely because the model was not exported with `output_attentions=True`."
            );
        }

        $medianFilterWidth = $this->config['median_filter_width'] ?? null;
        if ($medianFilterWidth === null) {
            trigger_error("Model config has no `median_filter_width`, using default value of 7.", E_USER_WARNING);
            $medianFilterWidth = 7;
        }

        $batchedMatrices = array_map(function ($batch) use ($numFrames, $alignmentHeads, $medianFilterWidth) {
            // Create a list with `decoder_layers` elements, each a tensor of shape
            // (batch size, attention_heads, output length, input length).
            /** @var Tensor[] $crossAttentions */
            $crossAttentions = [];
            for ($i = 0; $i < $this->config['decoder_layers']; $i++) {
                $crossAttentions[] = Tensor::concat(array_map(fn($x) => $x[$i], $batch), 2);
            }

            $weights = Tensor::stack(array_map(function ($alignmentHead) use ($crossAttentions, $numFrames) {
                [$l, $h] = $alignmentHead;
                return $numFrames
                    ? $crossAttentions[$l]->slice(null, $h, null, [0, $numFrames])
                    : $crossAttentions[$l]->slice(null, $h); // experimental
            }, $alignmentHeads));

            $weights = $weights->squeeze(1)->permute(1, 0, 2, 3);

            [$std, $calculatedMean] = $weights->stdMean(-2, 0, true);

            // Normalize and smoothen the weights.
            $smoothedWeights = clone $weights; // [1, 8, seqLength, 1500]

            for ($a = 0; $a < $smoothedWeights->shape()[0]; ++$a) {
                $aTensor = $smoothedWeights[$a]; // [8, seqLength, 1500]

                for ($b = 0; $b < $aTensor->shape()[0]; ++$b) {
                    $bTensor = $aTensor[$b]; // [seqLength, 1500]

                    $stdTensor = $std[$a][$b][0]; // [1500]
                    $meanTensor = $calculatedMean[$a][$b][0]; // [1500]

                    for ($c = 0; $c < $bTensor->shape()[0]; ++$c) {
                        /** @var Tensor $cTensor */
                        $cTensor = $bTensor[$c]; // [1500]

                        $cTensor
                            ->add($meanTensor->multiply(-1))
                            ->multiply($stdTensor->reciprocal())
                            ->copyTo($cTensor);

                        // Apply median filter.
                        $this->medianFilter($cTensor, $medianFilterWidth)->copyTo($cTensor);
                    }
                }
            }

            // Average the different cross-attention heads.
            return $smoothedWeights->mean(1);
        }, $generateOutputs['cross_attentions']);

        $timestampsShape = [count($generateOutputs['sequences']), count($generateOutputs['sequences'][0])];


        $timestamps = Tensor::zeros($timestampsShape, Tensor::float32);

        // Perform dynamic time warping on each element of the batch.
        for ($batchIdx = 0; $batchIdx < $timestampsShape[0]; ++$batchIdx) {
            // NOTE: Since we run only one batch at a time, we can squeeze to get the same dimensions
            // as the python implementation
            $matrix = $batchedMatrices[$batchIdx]->multiply(-1)->squeeze(0);
            [$textIndices, $timeIndices] = $this->dynamicTimeWarping($matrix);

            $diffs = array_map(fn($i) => $textIndices[$i + 1] - $textIndices[$i], range(0, count($textIndices) - 2));
            $jumps = array_map(fn($x) => (bool)$x, array_merge([1], $diffs));

            dd($timeIndices);
            $jumpTimes = [];
            for ($i = 0; $i < count($jumps); ++$i) {
                if ($jumps[$i]) {
                    $jumpTimes[] = $timeIndices[$i] * $timePrecision;
                }
            }
            dd($jumpTimes);
            for ($i = 1; $i < count($jumpTimes); ++$i) {
                $timestamps[$batchIdx][$i] = $jumpTimes[$i];
            }
        }

        return $timestamps;
    }

    function medianFilter(Tensor $tensor, int $windowSize): Tensor
    {
        if ($windowSize % 2 === 0 || $windowSize <= 0) {
            throw new InvalidArgumentException('Window size must be a positive odd number');
        }

        $outputArray = array_fill(0, count($tensor), 0);
        $buffer = array_fill(0, $windowSize, 0);

        $halfWindowSize = (int)floor($windowSize / 2);

        for ($i = 0; $i < count($tensor); ++$i) {
            $valuesIndex = 0;

            for ($j = -$halfWindowSize; $j <= $halfWindowSize; ++$j) {
                $index = $i + $j;
                if ($index < 0) {
                    $index = abs($index);
                } else if ($index >= count($tensor)) {
                    $index = 2 * (count($tensor) - 1) - $index;
                }

                $buffer[$valuesIndex++] = $tensor->buffer()[$index];
            }

            sort($buffer);
            $outputArray[$i] = $buffer[$halfWindowSize];
        }

        return Tensor::fromArray($outputArray, $tensor->dtype());
    }

    private function dynamicTimeWarping(Tensor $tensor): array
    {
        [$outputLength, $inputLength] = $tensor->shape();

        $outputShape = [$outputLength + 1, $inputLength + 1];

        $cost = Tensor::fill($outputShape, INF, Tensor::float32);
        $traceback = Tensor::fill($outputShape, -1, Tensor::int32);

        $cost[0][0] = 0;

        for ($j = 1; $j < $inputLength + 1; ++$j) {
            for ($i = 1; $i < $outputLength + 1; ++$i) {
                $c0 = $cost[$i - 1][$j - 1];
                $c1 = $cost[$i - 1][$j];
                $c2 = $cost[$i][$j - 1];

                if ($c0 < $c1 && $c0 < $c2) {
                    $c = $c0;
                    $t = 0;
                } else if ($c1 < $c0 && $c1 < $c2) {
                    $c = $c1;
                    $t = 1;
                } else {
                    $c = $c2;
                    $t = 2;
                }

                $cost[$i][$j] = $tensor[$i - 1][$j - 1] + $c;
                $traceback[$i][$j] = $t;
            }
        }

        // Traceback
        $i = $outputLength;
        $j = $inputLength;

        for ($k = 0; $k < $outputShape[1]; ++$k) {
            $traceback[0][$k] = 2; // trace[0, :] = 2
        }

        for ($k = 0; $k < $outputShape[0]; ++$k) {
            $traceback[$k][0] = 1; // trace[:, 0] = 1
        }

        $textIndices = [];
        $timeIndices = [];

        while ($i > 0 || $j > 0) {
            $textIndices[] = $i - 1;
            $timeIndices[] = $j - 1;

            $t = $traceback[$i][$j];

            if ($t === 0) {
                $i--;
                $j--;
            } else if ($t === 1) {
                $i--;
            } else {
                $j--;
            }
        }

        $textIndices = array_reverse($textIndices);
        $timeIndices = array_reverse($timeIndices);

        return [$textIndices, $timeIndices];
    }

}