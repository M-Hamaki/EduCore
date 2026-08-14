<?php
/**
 * YouTube Data API v3 Search Class
 * يبحث عن فيديوهات يوتيوب تعليمية حقيقية باستخدام Google YouTube Data API v3
 * 
 * Requires: YOUTUBE_API_KEY in .env
 * Free tier: 10,000 units/day (search costs 100 units per request)
 */

class YouTubeSearch {
    
    private $apiKey;
    private $baseUrl = 'https://www.googleapis.com/youtube/v3/search';
    private $lastError = '';
    
    public function __construct() {
        $this->apiKey = env('YOUTUBE_API_KEY', '');
    }
    
    /**
     * Check if YouTube API is available
     * @return bool
     */
    public function isAvailable() {
        return !empty($this->apiKey) && $this->apiKey !== 'your_youtube_api_key_here';
    }
    
    /**
     * Get last error message
     * @return string
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Search YouTube for educational videos
     * @param string $query Search query
     * @param int $maxResults Maximum number of results (1-5)
     * @param string $language Language code for relevance (ar, en, fr, etc.)
     * @return array Array of video results
     */
    public function search($query, $maxResults = 3, $language = 'ar') {
        if (!$this->isAvailable()) {
            $this->lastError = 'YouTube API key not configured';
            return [];
        }
        
        $maxResults = max(1, min(5, intval($maxResults)));
        
        // Map language to YouTube relevanceLanguage
        $langMap = [
            'ar' => 'ar',
            'en' => 'en',
            'fr' => 'fr',
            'de' => 'de'
        ];
        $relevanceLang = $langMap[$language] ?? 'en';
        
        $params = [
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'maxResults' => $maxResults,
            'order' => 'relevance',
            'relevanceLanguage' => $relevanceLang,
            'safeSearch' => 'strict',
            'videoEmbeddable' => 'true',
            'videoCategoryId' => '27', // Education category
            'key' => $this->apiKey
        ];
        
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->lastError = 'cURL error: ' . $curlError;
            error_log("YouTubeSearch cURL error: " . $curlError);
            return [];
        }
        
        if ($httpCode !== 200) {
            $this->lastError = 'YouTube API returned HTTP ' . $httpCode;
            error_log("YouTubeSearch API error (HTTP $httpCode): " . $response);
            return [];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['items'])) {
            $this->lastError = 'Invalid API response';
            error_log("YouTubeSearch: Invalid response: " . substr($response, 0, 500));
            return [];
        }
        
        $results = [];
        foreach ($data['items'] as $item) {
            $videoId = $item['id']['videoId'] ?? '';
            $snippet = $item['snippet'] ?? [];
            
            if (empty($videoId)) continue;
            
            $results[] = [
                'video_id' => $videoId,
                'video_url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'title' => $snippet['title'] ?? '',
                'description' => $snippet['description'] ?? '',
                'thumbnail' => $snippet['thumbnails']['high']['url'] ?? ($snippet['thumbnails']['medium']['url'] ?? ''),
                'channel_title' => $snippet['channelTitle'] ?? '',
                'published_at' => $snippet['publishedAt'] ?? ''
            ];
        }
        
        return $results;
    }
    
    /**
     * Search YouTube without category restriction (fallback for non-education content)
     * @param string $query Search query
     * @param int $maxResults Maximum results
     * @return array
     */
    public function searchGeneral($query, $maxResults = 3) {
        if (!$this->isAvailable()) {
            return [];
        }
        
        $params = [
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'maxResults' => max(1, min(5, intval($maxResults))),
            'order' => 'relevance',
            'safeSearch' => 'strict',
            'videoEmbeddable' => 'true',
            'key' => $this->apiKey
        ];
        
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [];
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['items'])) {
            return [];
        }
        
        $results = [];
        foreach ($data['items'] as $item) {
            $videoId = $item['id']['videoId'] ?? '';
            $snippet = $item['snippet'] ?? [];
            if (empty($videoId)) continue;
            
            $results[] = [
                'video_id' => $videoId,
                'video_url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'title' => $snippet['title'] ?? '',
                'description' => $snippet['description'] ?? '',
                'thumbnail' => $snippet['thumbnails']['high']['url'] ?? '',
                'channel_title' => $snippet['channelTitle'] ?? '',
                'published_at' => $snippet['publishedAt'] ?? ''
            ];
        }
        
        return $results;
    }
    
    /**
     * Enrich AI-generated YouTube suggestions with real video data
     * Takes the AI-generated search queries and searches YouTube for each
     * 
     * @param array $visualMaterials The visual materials data from AI
     * @param string $language Language code
     * @return array Updated visual materials with real YouTube videos
     */
    public function enrichYouTubeVideos($visualMaterials, $language = 'ar') {
        if (!$this->isAvailable() || !is_array($visualMaterials)) {
            return $visualMaterials;
        }
        
        $youtubeVideos = $visualMaterials['youtube_videos'] ?? [];
        
        if (empty($youtubeVideos) || !is_array($youtubeVideos)) {
            return $visualMaterials;
        }
        
        $enrichedVideos = [];
        
        foreach ($youtubeVideos as $video) {
            // إذا كان الفيديو يملك بالفعل رابط يوتيوب مباشر صحيح، نحتفظ به دون إعادة بحث
            if (!empty($video['video_url']) && preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $video['video_url'])) {
                $enrichedVideos[] = $video;
                continue;
            }

            $searchQuery = $video['search_query'] ?? $video['title'] ?? '';
            
            if (empty($searchQuery)) {
                $enrichedVideos[] = $video;
                continue;
            }
            
            // Search YouTube for this query
            $results = $this->search($searchQuery, 1, $language);
            
            // If education category returns no results, try general search
            if (empty($results)) {
                $results = $this->searchGeneral($searchQuery, 1);
            }
            
            if (!empty($results)) {
                $ytResult = $results[0];
                // Enrich the original AI suggestion with real YouTube data
                $video['video_url'] = $ytResult['video_url'];
                $video['youtube_title'] = $ytResult['title'];
                $video['youtube_description'] = $ytResult['description'];
                $video['thumbnail'] = $ytResult['thumbnail'];
                $video['channel_title'] = $ytResult['channel_title'];
            }
            
            $enrichedVideos[] = $video;
        }
        
        $visualMaterials['youtube_videos'] = $enrichedVideos;
        
        return $visualMaterials;
    }
}
