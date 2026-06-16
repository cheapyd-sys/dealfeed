<?php

namespace CAG\DealFeed\Service;

use XF\Service\AbstractService;

/**
 * Thin Guzzle wrapper around the worker's /api/deals endpoint.
 * Used by the cron entry; the widget only ever reads the cached result.
 */
class DealApiClient extends AbstractService
{
    public const API_BASE = 'https://deals.cheapassgamer.com/api/deals';

    /**
     * Fetch the deal feed for a time window. Returns an empty array on any
     * failure (network, non-200, malformed JSON) so callers can use a
     * truthy check to decide whether to keep prior cached data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(int $hours = 48, int $limit = 12): array
    {
        try {
            $response = $this->app->http()->client()->get(self::API_BASE, [
                'query'           => ['hours' => $hours, 'limit' => $limit],
                'connect_timeout' => 2,
                'timeout'         => 5,
                'http_errors'     => false,
                'headers'         => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'CAG/DealFeed: ');
            return [];
        }
    }
}
