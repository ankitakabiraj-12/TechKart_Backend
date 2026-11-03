<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Throwable;

/**
 * GeminiApiService handles secure communication with the Google Generative AI API.
 * Never exposes API keys to client devices.
 */
class GeminiApiService
{
    private string $apiKey;
    private string $defaultModel;
    private string $visionModel;
    private string $fallbackModel;

    public function __construct()
    {
        $this->apiKey = (string)Env::get('GEMINI_API_KEY', '');
        $this->defaultModel = (string)Env::get('GEMINI_MODEL', 'gemini-3.5-flash-lite');
        $this->visionModel = (string)Env::get('GEMINI_VISION_MODEL', 'gemini-3.5-flash-lite');
        $this->fallbackModel = (string)Env::get('GEMINI_FALLBACK_MODEL', 'gemini-3.1-flash-lite');
    }

    /**
     * Check if Gemini API is properly configured with an API key.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && strlen($this->apiKey) > 10;
    }

    /**
     * Generate text / chat response with multilingual awareness (English & Bengali).
     */
    public function generateChatResponse(string $systemPrompt, array $conversationHistory, string $userMessage): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $contents = [];

        // Insert conversation history if provided
        foreach ($conversationHistory as $msg) {
            $role = ($msg['sender'] === 'user') ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string)$msg['message']]],
            ];
        }

        // Append latest user message
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        $payload = [
            'contents' => $contents,
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 1024,
            ]
        ];

        $response = $this->callApi($this->defaultModel, $payload);
        if (!$response) {
            // Attempt fallback model
            $response = $this->callApi($this->fallbackModel, $payload);
        }

        if ($response && isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'text' => trim((string)$response['candidates'][0]['content']['parts'][0]['text']),
                'model' => $this->defaultModel,
            ];
        }

        return null;
    }

    /**
     * Perform visual multimodal inspection of an image.
     */
    public function analyzeProductImage(string $imagePath, string $prompt): ?array
    {
        if (!$this->isConfigured() || !file_exists($imagePath)) {
            return null;
        }

        try {
            $fileData = file_get_contents($imagePath);
            if ($fileData === false) {
                return null;
            }

            $mimeType = 'image/jpeg';
            $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            if ($ext === 'png') {
                $mimeType = 'image/png';
            } elseif ($ext === 'webp') {
                $mimeType = 'image/webp';
            }

            $base64Data = base64_encode($fileData);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Data
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 1024,
                ]
            ];

            $response = $this->callApi($this->visionModel, $payload);
            if (!$response) {
                $response = $this->callApi($this->fallbackModel, $payload);
            }

            if ($response && isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                $text = trim((string)$response['candidates'][0]['content']['parts'][0]['text']);

                // Clean potential markdown code blocks if returning JSON
                if (str_starts_with($text, '```json')) {
                    $text = substr($text, 7);
                } elseif (str_starts_with($text, '```')) {
                    $text = substr($text, 3);
                }
                if (str_ends_with($text, '```')) {
                    $text = substr($text, 0, -3);
                }
                $text = trim($text);

                $decoded = json_decode($text, true);
                return [
                    'raw_text' => $text,
                    'data' => is_array($decoded) ? $decoded : null,
                    'model' => $this->visionModel,
                ];
            }
        } catch (Throwable $e) {
            error_log('[GeminiApiService] Image analysis error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Low-level cURL call to Gemini API endpoint.
     */
    private function callApi(string $model, array $payload): ?array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: TechKart-Backend/2.0'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log(sprintf('[GeminiApiService] Call failed for model %s (HTTP %d): %s', $model, $httpCode, $error));
            return null;
        }

        $decoded = json_decode((string)$response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
