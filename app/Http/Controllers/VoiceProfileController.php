<?php

namespace App\Http\Controllers;

use App\Services\VoiceRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VoiceProfileController extends Controller
{
    private const ALGORITHM_ENERGY = 'energy-v1';
    private const ALGORITHM_ECAPA = 'ecapa-v1';

    private const ENERGY_DEFAULT_THRESHOLD = 0.68;
    private const ENERGY_MIN_THRESHOLD = 0.50;
    private const ENERGY_MAX_THRESHOLD = 0.74;
    private const ENERGY_THRESHOLD_MARGIN = 0.08;
    private const ENERGY_ADAPT_MIN_CONFIDENCE = 0.84;
    private const ENERGY_MIN_SAMPLE_BYTES = 8000;

    private const ECAPA_DEFAULT_THRESHOLD = 0.80;
    private const ECAPA_DEFAULT_SECONDARY_THRESHOLD = 0.74;
    private const ECAPA_MIN_THRESHOLD = 0.40;
    private const ECAPA_MIN_SECONDARY_THRESHOLD = 0.40;
    private const ECAPA_COMMAND_MIN_THRESHOLD = 0.25;
    private const ECAPA_COMMAND_MIN_SECONDARY_THRESHOLD = 0.25;
    private const ECAPA_MAX_THRESHOLD = 0.85;
    private const ECAPA_THRESHOLD_MARGIN = 0.05;
    private const ECAPA_SECONDARY_MARGIN = 0.06;
    private const ECAPA_ADAPT_MIN_CONFIDENCE = 0.82;
    private const ECAPA_ENROLL_MIN_PAIR_SIMILARITY = 0.75;
    private const ECAPA_ENROLL_OPTIONAL_THIRD_MIN = 0.70;
    private const ECAPA_MAX_EMBEDDINGS = 24;
    private const ENERGY_ENROLL_MIN_SAMPLE_SIMILARITY = 0.84;

    public function status(Request $request)
    {
        $walletAddress = trim((string) $request->query('wallet_address', ''));

        if ($walletAddress === '') {
            return response()->json(['message' => 'wallet_address is required'], 422);
        }

        $profile = DB::table('voice_profiles')->where('wallet_address', $walletAddress)->first();
        $algorithm = $profile->algorithm_version ?? self::ALGORITHM_ENERGY;

        return response()->json([
            'enrolled' => $profile !== null,
            'sample_count' => (int) ($profile->sample_count ?? 0),
            'threshold' => (float) ($profile->threshold ?? $this->defaultThresholdFor($algorithm)),
            'adapt_min_confidence' => $this->adaptMinConfidenceFor($algorithm),
            'last_verified_at' => $profile->last_verified_at ?? null,
            'algorithm_version' => $algorithm,
            'voice_ai_enabled' => VoiceRecognitionService::isEnabled(),
            'voice_ai_url' => VoiceRecognitionService::isEnabled() ? VoiceRecognitionService::baseUrl() : null,
            'voice_stream_verify_enabled' => VoiceRecognitionService::isEnabled()
                && VoiceRecognitionService::streamEnabled(),
        ]);
    }

    public function streamSession(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (!VoiceRecognitionService::isEnabled() || !VoiceRecognitionService::streamEnabled()) {
            return response()->json([
                'message' => 'Streaming voice verification is not enabled',
            ], 503);
        }

        $walletAddress = trim((string) $request->input('wallet_address'));
        $profile = DB::table('voice_profiles')->where('wallet_address', $walletAddress)->first();

        if (!$profile) {
            return response()->json([
                'message' => 'Voice enrollment required',
            ], 404);
        }

        if ((string) ($profile->algorithm_version ?? self::ALGORITHM_ENERGY) !== self::ALGORITHM_ECAPA) {
            return response()->json([
                'message' => 'Streaming verification requires ECAPA enrollment',
            ], 422);
        }

        $referenceEmbeddings = $this->profileEmbeddings($profile);
        if (empty($referenceEmbeddings)) {
            return response()->json([
                'message' => 'Voice profile is invalid. Please re-enroll your voice.',
            ], 422);
        }

        $thresholds = $this->commandEcapaThresholds($profile);

        try {
            $session = VoiceRecognitionService::createStreamSession(
                $referenceEmbeddings,
                $thresholds['primary'],
                $thresholds['secondary'],
            );
        } catch (\Throwable $exception) {
            Log::warning('voice.stream.session_failed', [
                'wallet' => $walletAddress,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not start voice stream session: ' . $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'session_id' => $session['session_id'],
            'ws_url' => $session['ws_url'],
            'expires_in' => $session['expires_in'],
            'threshold' => $thresholds['primary'],
            'secondary_threshold' => $thresholds['secondary'],
            'algorithm_version' => self::ALGORITHM_ECAPA,
        ]);
    }

    public function verifyStreamComplete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'session_id' => 'required|string|max:128',
            'adapt' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (!VoiceRecognitionService::isEnabled()) {
            return response()->json([
                'message' => 'Voice AI service is unavailable',
            ], 503);
        }

        $walletAddress = trim((string) $request->input('wallet_address'));
        $sessionId = trim((string) $request->input('session_id'));
        $profile = DB::table('voice_profiles')->where('wallet_address', $walletAddress)->first();

        if (!$profile) {
            return response()->json([
                'verified' => false,
                'enrolled' => false,
                'confidence' => 0,
                'transcript' => '',
                'message' => 'Voice enrollment required',
            ], 404);
        }

        $referenceEmbeddings = $this->profileEmbeddings($profile);
        $thresholds = $this->commandEcapaThresholds($profile);
        $storedThreshold = (float) ($profile->threshold ?? self::ECAPA_DEFAULT_THRESHOLD);

        try {
            $sessionResult = VoiceRecognitionService::getStreamSessionResult($sessionId);
        } catch (\Throwable $exception) {
            Log::warning('voice.stream.complete_lookup_failed', [
                'wallet' => $walletAddress,
                'session_id' => $sessionId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Voice stream session lookup failed: ' . $exception->getMessage(),
            ], 502);
        }

        if (empty($sessionResult['ready']) || !is_array($sessionResult['result'] ?? null)) {
            return response()->json([
                'message' => 'Voice stream session is not ready yet',
            ], 409);
        }

        $stream = $sessionResult['result'];
        $streamType = (string) ($stream['type'] ?? '');
        $verified = $streamType === 'verified' && !empty($stream['verified']);
        $confidence = round((float) ($stream['confidence'] ?? 0), 4);
        $secondaryConfidence = round((float) ($stream['secondary_confidence'] ?? 0), 4);
        $rejectionReason = $stream['rejection_reason'] ?? null;
        $isolatedAudioBase64 = is_string($stream['isolated_audio'] ?? null) ? $stream['isolated_audio'] : '';
        $streamEmbedding = is_array($stream['embedding'] ?? null) && $this->isNumericVector($stream['embedding'])
            ? array_values($stream['embedding'])
            : null;

        if (!$verified || $isolatedAudioBase64 === '') {
            return response()->json([
                'verified' => false,
                'enrolled' => true,
                'confidence' => $confidence,
                'secondary_confidence' => $secondaryConfidence,
                'threshold' => (float) ($stream['threshold'] ?? $thresholds['primary']),
                'secondary_threshold' => (float) ($stream['secondary_threshold'] ?? $thresholds['secondary']),
                'stored_threshold' => $storedThreshold,
                'adapted' => false,
                'transcript' => '',
                'scores' => $stream['scores'] ?? [],
                'duration_seconds' => null,
                'speaker_count' => (int) ($stream['speaker_count'] ?? 0),
                'target_segment_count' => (int) ($stream['target_segment_count'] ?? 0),
                'target_speech_seconds' => isset($stream['target_speech_seconds'])
                    ? round((float) $stream['target_speech_seconds'], 3)
                    : null,
                'rejection_reason' => $rejectionReason,
                'algorithm_version' => self::ALGORITHM_ECAPA,
                'message' => $this->ecapaRejectionMessage($rejectionReason),
            ]);
        }

        $transcript = $this->transcribe($isolatedAudioBase64);
        $shouldAdapt = $request->boolean('adapt', true)
            && $confidence >= self::ECAPA_ADAPT_MIN_CONFIDENCE
            && $streamEmbedding !== null;

        if ($shouldAdapt) {
            try {
                $this->adaptEcapaProfile(
                    $walletAddress,
                    $referenceEmbeddings,
                    $streamEmbedding,
                    (int) $profile->sample_count
                );
            } catch (\Throwable $exception) {
                Log::warning('voice.stream.adapt_failed', [
                    'wallet' => $walletAddress,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'verified' => true,
            'enrolled' => true,
            'confidence' => $confidence,
            'secondary_confidence' => $secondaryConfidence,
            'threshold' => (float) ($stream['threshold'] ?? $thresholds['primary']),
            'secondary_threshold' => (float) ($stream['secondary_threshold'] ?? $thresholds['secondary']),
            'stored_threshold' => $storedThreshold,
            'adapted' => $shouldAdapt,
            'transcript' => $transcript,
            'scores' => $stream['scores'] ?? [],
            'duration_seconds' => isset($stream['target_speech_seconds'])
                ? round((float) $stream['target_speech_seconds'], 3)
                : null,
            'speaker_count' => (int) ($stream['speaker_count'] ?? 0),
            'target_segment_count' => (int) ($stream['target_segment_count'] ?? 0),
            'target_speech_seconds' => isset($stream['target_speech_seconds'])
                ? round((float) $stream['target_speech_seconds'], 3)
                : null,
            'rejection_reason' => null,
            'algorithm_version' => self::ALGORITHM_ECAPA,
            'message' => 'Voice recognized',
        ]);
    }

    public function enroll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'nullable|string|max:255',
            'samples' => 'required|array|min:3|max:6',
            'samples.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (VoiceRecognitionService::isEnabled()) {
            return $this->enrollWithEcapa($request);
        }

        return $this->enrollWithEnergy($request);
    }

    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'audio' => 'required|string',
            'adapt' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim((string) $request->input('wallet_address'));
        $profile = DB::table('voice_profiles')->where('wallet_address', $walletAddress)->first();

        if (!$profile) {
            return response()->json([
                'verified' => false,
                'enrolled' => false,
                'confidence' => 0,
                'transcript' => '',
                'message' => 'Voice enrollment required',
            ], 404);
        }

        $algorithm = (string) ($profile->algorithm_version ?? self::ALGORITHM_ENERGY);

        if ($algorithm === self::ALGORITHM_ECAPA) {
            if (!VoiceRecognitionService::isEnabled()) {
                return response()->json([
                    'message' => 'Voice AI service is unavailable. Set VOICE_AI_URL and start the python-speech-brain service.',
                ], 503);
            }

            return $this->verifyWithEcapa($request, $profile);
        }

        return $this->verifyWithEnergy($request, $profile);
    }

    private function enrollWithEcapa(Request $request)
    {
        $samples = $request->input('samples');
        $this->extendVoiceAiRuntime(count($samples));

        $embeddings = [];
        $durations = [];

        try {
            foreach ($samples as $index => $sample) {
                $result = VoiceRecognitionService::embed((string) $sample);
                $embedding = $this->embeddingFromVoiceAiResult($result);
                $embeddings[] = $embedding;
                $durations[] = $result['duration_seconds'] ?? null;

                Log::info('voice.enroll.ecapa_sample_embedded', [
                    'sample_index' => $index,
                    'embedding_dimension' => count($embedding),
                    'duration_seconds' => $result['duration_seconds'] ?? null,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('voice.enroll.ecapa_failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'Voice enrollment failed: ' . $exception->getMessage(),
            ], 502);
        }

        $gallery = $this->buildEcapaEnrollmentGallery($embeddings);
        if (isset($gallery['error'])) {
            return $gallery['error'];
        }

        $now = now();
        $walletAddress = trim((string) $request->input('wallet_address'));

        DB::table('voice_profiles')->updateOrInsert(
            ['wallet_address' => $walletAddress],
            [
                'username' => $request->input('username'),
                'centroid' => json_encode([
                    'embeddings' => $gallery['embeddings'],
                    'secondary_threshold' => $gallery['secondary_threshold'],
                ]),
                'sample_count' => count($gallery['embeddings']),
                'threshold' => $gallery['primary_threshold'],
                'algorithm_version' => self::ALGORITHM_ECAPA,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return response()->json([
            'status' => 'success',
            'enrolled' => true,
            'sample_count' => count($gallery['embeddings']),
            'threshold' => $gallery['primary_threshold'],
            'secondary_threshold' => $gallery['secondary_threshold'],
            'sample_scores' => array_map(fn ($score) => round($score, 4), $gallery['sample_scores']),
            'accepted_sample_indexes' => $gallery['accepted_indexes'],
            'algorithm_version' => self::ALGORITHM_ECAPA,
            'durations_seconds' => $durations,
        ]);
    }

    private function extendVoiceAiRuntime(int $sampleCount): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        $seconds = max(120, ($sampleCount * VoiceRecognitionService::timeout()) + 30);
        @set_time_limit($seconds);
    }

    /**
     * @return array<int, float>
     */
    private function embeddingFromVoiceAiResult(array $result): array
    {
        $embedding = $result['embedding'] ?? null;
        if (!is_array($embedding) || !$this->isNumericVector($embedding)) {
            throw new \RuntimeException('Voice AI returned an invalid embedding response');
        }

        return array_map(fn ($value) => (float) $value, array_values($embedding));
    }

    private function verifyWithEcapa(Request $request, object $profile)
    {
        $walletAddress = trim((string) $request->input('wallet_address'));
        $audioBase64 = (string) $request->input('audio');
        $referenceEmbeddings = $this->profileEmbeddings($profile);

        if (empty($referenceEmbeddings)) {
            return response()->json([
                'message' => 'Voice profile is invalid. Please re-enroll your voice.',
            ], 422);
        }

        $thresholds = $this->commandEcapaThresholds($profile);
        $storedThreshold = (float) ($profile->threshold ?? self::ECAPA_DEFAULT_THRESHOLD);
        $isolatedAudioBase64 = '';

        try {
            $isolation = VoiceRecognitionService::isolate(
                $audioBase64,
                $referenceEmbeddings,
                $thresholds['primary'],
                $thresholds['secondary'],
            );

            if (empty($isolation['isolated']) || !is_string($isolation['isolated_audio'] ?? null) || $isolation['isolated_audio'] === '') {
                $rejectionReason = $isolation['rejection_reason'] ?? 'speaker_not_recognized';

                return response()->json([
                    'verified' => false,
                    'enrolled' => true,
                    'confidence' => round((float) ($isolation['confidence'] ?? 0), 4),
                    'secondary_confidence' => round((float) ($isolation['secondary_confidence'] ?? 0), 4),
                    'threshold' => (float) ($isolation['threshold'] ?? $thresholds['primary']),
                    'secondary_threshold' => (float) ($isolation['secondary_threshold'] ?? $thresholds['secondary']),
                    'stored_threshold' => $storedThreshold,
                    'adapted' => false,
                    'transcript' => '',
                    'scores' => $isolation['scores'] ?? [],
                    'duration_seconds' => null,
                    'speaker_count' => (int) ($isolation['speaker_count'] ?? 0),
                    'target_segment_count' => (int) ($isolation['target_segment_count'] ?? 0),
                    'target_speech_seconds' => isset($isolation['target_speech_seconds'])
                        ? round((float) $isolation['target_speech_seconds'], 3)
                        : null,
                    'rejection_reason' => $rejectionReason,
                    'algorithm_version' => self::ALGORITHM_ECAPA,
                    'message' => $this->ecapaRejectionMessage($rejectionReason),
                ]);
            }

            $isolatedAudioBase64 = (string) $isolation['isolated_audio'];
            $result = VoiceRecognitionService::verify(
                $isolatedAudioBase64,
                $referenceEmbeddings,
                $thresholds['primary'],
                $thresholds['secondary'],
            );
        } catch (\Throwable $exception) {
            Log::warning('voice.verify.ecapa_failed', [
                'wallet' => $walletAddress,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Voice verification failed: ' . $exception->getMessage(),
            ], 502);
        }

        $confidence = (float) ($result['confidence'] ?? 0);
        $secondaryConfidence = (float) ($result['secondary_confidence'] ?? 0);
        $verified = (bool) ($result['verified'] ?? false);
        $rejectionReason = $result['rejection_reason'] ?? null;
        $transcript = $verified ? $this->transcribe($isolatedAudioBase64) : '';
        $shouldAdapt = $request->boolean('adapt', true)
            && $verified
            && $confidence >= self::ECAPA_ADAPT_MIN_CONFIDENCE;

        if ($shouldAdapt && !empty($result['embedding'])) {
            $this->adaptEcapaProfile($walletAddress, $referenceEmbeddings, $result['embedding'], (int) $profile->sample_count);
        }

        return response()->json([
            'verified' => $verified,
            'enrolled' => true,
            'confidence' => round($confidence, 4),
            'secondary_confidence' => round($secondaryConfidence, 4),
            'threshold' => (float) ($result['threshold'] ?? $thresholds['primary']),
            'secondary_threshold' => (float) ($result['secondary_threshold'] ?? $thresholds['secondary']),
            'stored_threshold' => $storedThreshold,
            'adapted' => $shouldAdapt,
            'transcript' => $transcript,
            'scores' => $result['scores'] ?? [],
            'duration_seconds' => $result['duration_seconds'] ?? null,
            'speaker_count' => (int) ($isolation['speaker_count'] ?? 0),
            'target_segment_count' => (int) ($isolation['target_segment_count'] ?? 0),
            'target_speech_seconds' => isset($isolation['target_speech_seconds'])
                ? round((float) $isolation['target_speech_seconds'], 3)
                : null,
            'rejection_reason' => $rejectionReason,
            'algorithm_version' => self::ALGORITHM_ECAPA,
            'message' => $verified
                ? 'Voice recognized'
                : $this->ecapaRejectionMessage($rejectionReason),
        ]);
    }

    private function enrollWithEnergy(Request $request)
    {
        $vectors = [];
        foreach ($request->input('samples') as $sample) {
            $vectors[] = $this->voiceVectorFromBase64((string) $sample);
        }

        $pairwiseScores = $this->pairwiseEnrollmentScores($vectors);
        $enrollmentCheck = $this->validateEnrollmentSampleSimilarity(
            $pairwiseScores,
            self::ENERGY_ENROLL_MIN_SAMPLE_SIMILARITY
        );
        if ($enrollmentCheck !== null) {
            return $enrollmentCheck;
        }

        $centroid = $this->centroid($vectors);
        $sampleScores = array_map(
            fn (array $vector) => $this->cosineSimilarity($centroid, $vector),
            $vectors
        );
        $threshold = $this->calibrateEnergyThreshold($sampleScores);
        $now = now();

        DB::table('voice_profiles')->updateOrInsert(
            ['wallet_address' => trim((string) $request->input('wallet_address'))],
            [
                'username' => $request->input('username'),
                'centroid' => json_encode($centroid),
                'sample_count' => count($vectors),
                'threshold' => $threshold,
                'algorithm_version' => self::ALGORITHM_ENERGY,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return response()->json([
            'status' => 'success',
            'enrolled' => true,
            'sample_count' => count($vectors),
            'threshold' => $threshold,
            'sample_scores' => array_map(fn ($score) => round($score, 4), $sampleScores),
            'algorithm_version' => self::ALGORITHM_ENERGY,
        ]);
    }

    private function verifyWithEnergy(Request $request, object $profile)
    {
        $walletAddress = trim((string) $request->input('wallet_address'));
        $audioBase64 = (string) $request->input('audio');
        $vector = $this->voiceVectorFromBase64($audioBase64);
        $profileVector = json_decode((string) $profile->centroid, true);
        $confidence = $this->cosineSimilarity($profileVector, $vector);
        $storedThreshold = (float) ($profile->threshold ?? self::ENERGY_DEFAULT_THRESHOLD);
        $threshold = $this->effectiveEnergyThreshold($storedThreshold);
        $verified = $confidence >= $threshold;
        $transcript = $verified ? $this->transcribe($audioBase64) : '';

        if ($verified && $confidence >= self::ENERGY_ADAPT_MIN_CONFIDENCE && $request->boolean('adapt', true)) {
            $this->adaptEnergyProfile($walletAddress, $profileVector, $vector, (int) $profile->sample_count);
        }

        return response()->json([
            'verified' => $verified,
            'enrolled' => true,
            'confidence' => round($confidence, 4),
            'threshold' => $threshold,
            'stored_threshold' => $storedThreshold,
            'adapted' => $verified && $confidence >= self::ENERGY_ADAPT_MIN_CONFIDENCE && $request->boolean('adapt', true),
            'transcript' => $transcript,
            'algorithm_version' => self::ALGORITHM_ENERGY,
            'message' => $verified ? 'Voice recognized' : 'Voice not recognized',
        ]);
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function profileEmbeddings(object $profile): array
    {
        $decoded = json_decode((string) $profile->centroid, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (isset($decoded['embeddings']) && is_array($decoded['embeddings'])) {
            return array_values($decoded['embeddings']);
        }

        if ($this->isNumericVector($decoded)) {
            return [$decoded];
        }

        return [];
    }

    /**
     * Each sample's best cosine match against the other enrollment samples.
     *
     * @param  array<int, array<int, float>>  $vectors
     * @return array<int, float>
     */
    private function pairwiseEnrollmentScores(array $vectors): array
    {
        $scores = [];

        foreach ($vectors as $index => $vector) {
            $best = 0.0;
            foreach ($vectors as $otherIndex => $other) {
                if ($index === $otherIndex) {
                    continue;
                }
                $best = max($best, $this->cosineSimilarity($vector, $other));
            }
            $scores[] = $best;
        }

        return $scores;
    }

    /**
     * @param  array<int, float>  $sampleScores
     */
    private function validateEnrollmentSampleSimilarity(array $sampleScores, float $requiredSimilarity): ?\Illuminate\Http\JsonResponse
    {
        if ($sampleScores === []) {
            return response()->json(['message' => 'At least one voice sample is required'], 422);
        }

        $lowestSampleScore = min($sampleScores);
        if ($lowestSampleScore >= $requiredSimilarity) {
            return null;
        }

        return response()->json([
            'message' => 'Your voice samples do not sound similar enough. Record all 3 again in a quiet place, speaking naturally with your own voice.',
            'sample_scores' => array_map(fn ($score) => round($score, 4), $sampleScores),
            'required_similarity' => $requiredSimilarity,
            'lowest_similarity' => round($lowestSampleScore, 4),
        ], 422);
    }

    /**
     * @param  array<int, float>  $sampleScores
     */
    private function calibrateEcapaThreshold(array $sampleScores): float
    {
        if (empty($sampleScores)) {
            return self::ECAPA_DEFAULT_THRESHOLD;
        }

        $threshold = min($sampleScores) - self::ECAPA_THRESHOLD_MARGIN;

        return round(max(self::ECAPA_MIN_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $threshold)), 4);
    }

    private function calibrateEcapaSecondaryThreshold(float $primaryThreshold): float
    {
        $secondary = $primaryThreshold - self::ECAPA_SECONDARY_MARGIN;

        return round(
            max(self::ECAPA_MIN_SECONDARY_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $secondary)),
            4
        );
    }

    private function effectiveEcapaThreshold(float $storedThreshold): float
    {
        $configuredThreshold = VoiceRecognitionService::defaultThreshold();
        $threshold = min($storedThreshold, $configuredThreshold);

        return round(max(self::ECAPA_MIN_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $threshold)), 4);
    }

    /**
     * @return array{primary: float, secondary: float}
     */
    private function profileEcapaThresholds(object $profile): array
    {
        $storedPrimary = (float) ($profile->threshold ?? self::ECAPA_DEFAULT_THRESHOLD);
        $primary = $this->effectiveEcapaThreshold($storedPrimary);

        $decoded = json_decode((string) $profile->centroid, true);
        $storedSecondary = is_array($decoded) ? ($decoded['secondary_threshold'] ?? null) : null;

        if (is_numeric($storedSecondary)) {
            $secondary = min((float) $storedSecondary, VoiceRecognitionService::defaultSecondaryThreshold());
            $secondary = max(self::ECAPA_MIN_SECONDARY_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $secondary));
        } else {
            $secondary = $this->calibrateEcapaSecondaryThreshold($primary);
        }

        return [
            'primary' => $primary,
            'secondary' => round($secondary, 4),
        ];
    }

    /**
     * Mobile command clips are shorter/noisier than enrollment phrases, so use a
     * separate command gate while keeping enrollment thresholds for profile quality.
     *
     * @return array{primary: float, secondary: float}
     */
    private function commandEcapaThresholds(object $profile): array
    {
        $profileThresholds = $this->profileEcapaThresholds($profile);

        $primary = min(
            $profileThresholds['primary'],
            VoiceRecognitionService::commandThreshold()
        );
        $secondary = min(
            $profileThresholds['secondary'],
            VoiceRecognitionService::commandSecondaryThreshold()
        );

        return [
            'primary' => round(
                max(self::ECAPA_COMMAND_MIN_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $primary)),
                4
            ),
            'secondary' => round(
                max(self::ECAPA_COMMAND_MIN_SECONDARY_THRESHOLD, min(self::ECAPA_MAX_THRESHOLD, $secondary)),
                4
            ),
        ];
    }

    /**
     * @param  array<int, array<int, float>>  $embeddings
     * @return array{
     *   embeddings: array<int, array<int, float>>,
     *   sample_scores: array<int, float>,
     *   primary_threshold: float,
     *   secondary_threshold: float,
     *   accepted_indexes: array<int, int>,
     *   error?: \Illuminate\Http\JsonResponse
     * }
     */
    private function buildEcapaEnrollmentGallery(array $embeddings): array
    {
        $count = count($embeddings);
        $sampleScores = $this->pairwiseEnrollmentScores($embeddings);

        if ($count < 2) {
            return [
                'error' => response()->json(['message' => 'At least two voice samples are required'], 422),
            ];
        }

        $pairScores = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairScores[] = [
                    'i' => $i,
                    'j' => $j,
                    'score' => $this->cosineSimilarity($embeddings[$i], $embeddings[$j]),
                ];
            }
        }

        usort($pairScores, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $bestPair = $pairScores[0] ?? null;

        if ($bestPair === null || $bestPair['score'] < self::ECAPA_ENROLL_MIN_PAIR_SIMILARITY) {
            return [
                'error' => response()->json([
                    'message' => 'Your voice samples do not sound similar enough. Record all 3 again using the same phrase, pace, and distance from the microphone.',
                    'sample_scores' => array_map(fn ($score) => round($score, 4), $sampleScores),
                    'required_pair_similarity' => self::ECAPA_ENROLL_MIN_PAIR_SIMILARITY,
                    'best_pair_similarity' => isset($bestPair['score']) ? round((float) $bestPair['score'], 4) : 0,
                ], 422),
            ];
        }

        $acceptedIndexes = [(int) $bestPair['i'], (int) $bestPair['j']];
        for ($index = 0; $index < $count; $index++) {
            if (in_array($index, $acceptedIndexes, true)) {
                continue;
            }

            $toAccepted = max(
                $this->cosineSimilarity($embeddings[$index], $embeddings[$acceptedIndexes[0]]),
                $this->cosineSimilarity($embeddings[$index], $embeddings[$acceptedIndexes[1]])
            );

            if ($toAccepted >= self::ECAPA_ENROLL_OPTIONAL_THIRD_MIN) {
                $acceptedIndexes[] = $index;
            }
        }

        $acceptedEmbeddings = [];
        $acceptedSampleScores = [];
        foreach ($acceptedIndexes as $acceptedIndex) {
            $acceptedEmbeddings[] = $embeddings[$acceptedIndex];

            $best = 0.0;
            foreach ($acceptedIndexes as $otherIndex) {
                if ($acceptedIndex === $otherIndex) {
                    continue;
                }
                $best = max($best, $this->cosineSimilarity($embeddings[$acceptedIndex], $embeddings[$otherIndex]));
            }
            $acceptedSampleScores[] = $best;
        }

        $primaryThreshold = $this->calibrateEcapaThreshold($acceptedSampleScores);
        $secondaryThreshold = $this->calibrateEcapaSecondaryThreshold($primaryThreshold);

        return [
            'embeddings' => array_values($acceptedEmbeddings),
            'sample_scores' => $sampleScores,
            'primary_threshold' => $primaryThreshold,
            'secondary_threshold' => $secondaryThreshold,
            'accepted_indexes' => array_values($acceptedIndexes),
        ];
    }

    private function effectiveEnergyThreshold(float $storedThreshold): float
    {
        return round(max(self::ENERGY_MIN_THRESHOLD, min(self::ENERGY_MAX_THRESHOLD, $storedThreshold)), 4);
    }

    /**
     * @param  array<int, array<int, float>>  $embeddings
     * @param  array<int, float>  $newEmbedding
     */
    private function adaptEcapaProfile(string $walletAddress, array $embeddings, array $newEmbedding, int $sampleCount): void
    {
        $embeddings[] = $newEmbedding;

        if (count($embeddings) > self::ECAPA_MAX_EMBEDDINGS) {
            $embeddings = array_slice($embeddings, -self::ECAPA_MAX_EMBEDDINGS);
        }

        $profile = DB::table('voice_profiles')->where('wallet_address', $walletAddress)->first();
        $decoded = json_decode((string) ($profile->centroid ?? '{}'), true);
        $secondaryThreshold = is_array($decoded) && is_numeric($decoded['secondary_threshold'] ?? null)
            ? (float) $decoded['secondary_threshold']
            : self::ECAPA_DEFAULT_SECONDARY_THRESHOLD;

        DB::table('voice_profiles')
            ->where('wallet_address', $walletAddress)
            ->update([
                'centroid' => json_encode([
                    'embeddings' => array_values($embeddings),
                    'secondary_threshold' => $secondaryThreshold,
                ]),
                'sample_count' => $sampleCount + 1,
                'last_verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function adaptEnergyProfile(string $walletAddress, array $profileVector, array $newVector, int $sampleCount): void
    {
        $weight = 0.05;
        $adapted = [];
        for ($i = 0; $i < count($profileVector); $i++) {
            $adapted[$i] = ((float) $profileVector[$i] * (1 - $weight)) + ((float) $newVector[$i] * $weight);
        }

        DB::table('voice_profiles')
            ->where('wallet_address', $walletAddress)
            ->update([
                'centroid' => json_encode($this->normalize($adapted)),
                'sample_count' => $sampleCount + 1,
                'last_verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function ecapaRejectionMessage(?string $rejectionReason): string
    {
        return match ($rejectionReason) {
            'no_speech_detected' => 'No clear speech detected. Speak closer to the microphone.',
            'insufficient_target_speech' => 'Not enough of your voice was detected. Try again in a quieter place.',
            'ambiguous_speakers' => 'Multiple voices detected. Move to a quieter place and speak alone.',
            'inconsistent_match' => 'Voice match was not consistent enough. Try again speaking clearly in a quiet place.',
            'speaker_not_recognized' => 'Voice not recognized',
            default => 'Voice not recognized',
        };
    }

    private function defaultThresholdFor(string $algorithm): float
    {
        return $algorithm === self::ALGORITHM_ECAPA
            ? self::ECAPA_DEFAULT_THRESHOLD
            : self::ENERGY_DEFAULT_THRESHOLD;
    }

    private function adaptMinConfidenceFor(string $algorithm): float
    {
        return $algorithm === self::ALGORITHM_ECAPA
            ? self::ECAPA_ADAPT_MIN_CONFIDENCE
            : self::ENERGY_ADAPT_MIN_CONFIDENCE;
    }

    private function isNumericVector(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_numeric($item)) {
                return false;
            }
        }

        return true;
    }

    private function voiceVectorFromBase64(string $base64Audio): array
    {
        $audio = $this->decodeAudio($base64Audio);
        $pcm = $this->extractPcm($audio);

        if (strlen($pcm) < self::ENERGY_MIN_SAMPLE_BYTES) {
            throw new HttpResponseException(response()->json(['message' => 'Audio sample is too short'], 422));
        }

        $samples = [];
        $length = strlen($pcm) - (strlen($pcm) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $value = unpack('v', substr($pcm, $i, 2))[1];
            if ($value >= 32768) {
                $value -= 65536;
            }
            $samples[] = $value / 32768;
        }

        $chunkCount = 32;
        $chunkSize = max(1, intdiv(count($samples), $chunkCount));
        $features = [];

        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $start = $chunk * $chunkSize;
            $slice = array_slice($samples, $start, $chunkSize);
            if (empty($slice)) {
                $features[] = 0.0;
                continue;
            }

            $sumSquares = 0.0;
            $sumAbs = 0.0;
            $zeroCrossings = 0;
            $previous = $slice[0];

            foreach ($slice as $sample) {
                $sumSquares += $sample * $sample;
                $sumAbs += abs($sample);
                if (($previous < 0 && $sample >= 0) || ($previous >= 0 && $sample < 0)) {
                    $zeroCrossings++;
                }
                $previous = $sample;
            }

            $count = count($slice);
            $features[] = sqrt($sumSquares / $count);
            $features[] = $sumAbs / $count;
            $features[] = $zeroCrossings / $count;
        }

        return $this->normalize($features);
    }

    private function decodeAudio(string $base64Audio): string
    {
        if (str_contains($base64Audio, ',')) {
            [, $base64Audio] = explode(',', $base64Audio, 2);
        }

        $audio = base64_decode($base64Audio, true);
        if ($audio === false) {
            throw new HttpResponseException(response()->json(['message' => 'Invalid base64 audio'], 422));
        }

        return $audio;
    }

    private function extractPcm(string $audio): string
    {
        if (substr($audio, 0, 4) !== 'RIFF') {
            return $audio;
        }

        $dataOffset = strpos($audio, 'data');
        if ($dataOffset === false) {
            return substr($audio, 44);
        }

        return substr($audio, $dataOffset + 8);
    }

    private function transcribe(string $base64Audio): string
    {
        $apiKey = config('openai.api_key');
        if (!$apiKey) {
            return '';
        }

        $audio = $this->decodeAudio($base64Audio);
        if (substr($audio, 0, 4) !== 'RIFF') {
            $audio = $this->pcmToWav($audio);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->attach(
            'file',
            $audio,
            'voice-command-' . Str::uuid()->toString() . '.wav'
        )->post('https://api.openai.com/v1/audio/transcriptions', [
            'model' => 'gpt-4o-mini-transcribe',
            'prompt' => 'Transcribe a command for a Solana wallet assistant. Expect token names, SOL, USDC, swap, send, transfer, buy, sell, and contact names.',
        ]);

        if (!$response->successful()) {
            return '';
        }

        return trim((string) ($response->json('text') ?? ''));
    }

    private function pcmToWav(string $pcmData, int $sampleRate = 16000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataLength = strlen($pcmData);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $byteRate = $sampleRate * $blockAlign;

        return "RIFF" .
            pack('V', 36 + $dataLength) .
            "WAVEfmt " .
            pack('V', 16) .
            pack('v', 1) .
            pack('v', $channels) .
            pack('V', $sampleRate) .
            pack('V', $byteRate) .
            pack('v', $blockAlign) .
            pack('v', $bitsPerSample) .
            "data" .
            pack('V', $dataLength) .
            $pcmData;
    }

    private function centroid(array $vectors): array
    {
        $size = count($vectors[0] ?? []);
        $centroid = array_fill(0, $size, 0.0);

        foreach ($vectors as $vector) {
            for ($i = 0; $i < $size; $i++) {
                $centroid[$i] += (float) ($vector[$i] ?? 0);
            }
        }

        for ($i = 0; $i < $size; $i++) {
            $centroid[$i] /= max(1, count($vectors));
        }

        return $this->normalize($centroid);
    }

    private function normalize(array $vector): array
    {
        $magnitude = sqrt(array_reduce($vector, fn ($carry, $value) => $carry + ((float) $value * (float) $value), 0.0));
        if ($magnitude <= 0) {
            return $vector;
        }

        return array_map(fn ($value) => round(((float) $value) / $magnitude, 8), $vector);
    }

    private function cosineSimilarity(?array $a, array $b): float
    {
        if (!$a || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $dot += ((float) $a[$i]) * ((float) $b[$i]);
        }

        return max(0.0, min(1.0, $dot));
    }

    /**
     * @param  array<int, float>  $sampleScores
     */
    private function calibrateEnergyThreshold(array $sampleScores): float
    {
        if (empty($sampleScores)) {
            return self::ENERGY_DEFAULT_THRESHOLD;
        }

        $lowestEnrollmentScore = min($sampleScores);
        $threshold = $lowestEnrollmentScore - self::ENERGY_THRESHOLD_MARGIN;

        return round(max(self::ENERGY_MIN_THRESHOLD, min(self::ENERGY_MAX_THRESHOLD, $threshold)), 4);
    }
}
