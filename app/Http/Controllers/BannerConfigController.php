<?php

namespace App\Http\Controllers;

use App\Models\BannerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerConfigController extends Controller
{
    /**
     * GET /banner-config
     */
    public function show(): JsonResponse
    {
        $config = BannerConfig::current();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'autoplay'          => $config->autoplay,
                'autoplay_delay_ms' => $config->autoplay_delay_ms,
                'transition'        => $config->transition,
                'show_dots'         => $config->show_dots,
                'show_arrows'       => $config->show_arrows,
            ],
        ]);
    }

    /**
     * PUT /banner-config
     * Body: any subset of { autoplay, autoplay_delay_ms, transition, show_dots, show_arrows }
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'autoplay'          => 'nullable|boolean',
            'autoplay_delay_ms' => 'nullable|integer|min:1000|max:30000',
            'transition'        => 'nullable|in:fade,slide',
            'show_dots'         => 'nullable|boolean',
            'show_arrows'       => 'nullable|boolean',
        ]);

        $config = BannerConfig::updateConfig(array_filter(
            $request->only('autoplay', 'autoplay_delay_ms', 'transition', 'show_dots', 'show_arrows'),
            fn ($v) => $v !== null
        ));

        return response()->json([
            'status'  => 'success',
            'message' => 'Banner config updated.',
            'data'    => [
                'autoplay'          => $config->autoplay,
                'autoplay_delay_ms' => $config->autoplay_delay_ms,
                'transition'        => $config->transition,
                'show_dots'         => $config->show_dots,
                'show_arrows'       => $config->show_arrows,
            ],
        ]);
    }
}
