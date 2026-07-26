<?php

if (!defined('ABSPATH')) {
    exit;
}

$walkthrough_asset_url = static function (string $relative_path): string {
    return XRA_URL . ltrim($relative_path, '/');
};

$walkthrough_items = [
    [
        'title' => 'Customer Experience Walkthrough',
        'video' => [
            'desktop' => [
                'src' => $walkthrough_asset_url('assets/media/walkthroughs/videos/customer-desktop.mp4'),
                'poster' => $walkthrough_asset_url('assets/media/walkthroughs/posters/customer-desktop.png'),
                'captions' => $walkthrough_asset_url('assets/media/walkthroughs/captions/customer-desktop.srt'),
            ],
            'mobile' => [
                'src' => $walkthrough_asset_url('assets/media/walkthroughs/videos/customer-mobile.mp4'),
                'poster' => $walkthrough_asset_url('assets/media/walkthroughs/posters/customer-mobile.png'),
                'captions' => $walkthrough_asset_url('assets/media/walkthroughs/captions/customer-mobile.srt'),
            ],
        ],
    ],
    [
        'title' => 'Admin Experience Walkthrough',
        'video' => [
            'desktop' => [
                'src' => $walkthrough_asset_url('assets/media/walkthroughs/videos/admin-desktop.mp4'),
                'poster' => $walkthrough_asset_url('assets/media/walkthroughs/posters/admin-desktop.png'),
                'captions' => $walkthrough_asset_url('assets/media/walkthroughs/captions/admin-desktop.srt'),
            ],
            'mobile' => [
                'src' => $walkthrough_asset_url('assets/media/walkthroughs/videos/admin-mobile.mp4'),
                'poster' => $walkthrough_asset_url('assets/media/walkthroughs/posters/admin-mobile.png'),
                'captions' => $walkthrough_asset_url('assets/media/walkthroughs/captions/admin-mobile.srt'),
            ],
        ],
    ],
];

$walkthrough_manifest = [
    'slides' => $walkthrough_items,
];

$device_mode = function_exists('wp_is_mobile') && wp_is_mobile() ? 'mobile' : 'desktop';
$initial_title = (string) ($walkthrough_items[0]['title'] ?? 'Walkthrough');
$initial_media = (array) ($walkthrough_items[0]['video'][$device_mode] ?? []);
?>
<section class="xra-shell-surface xra-shell-surface--walkthroughs" aria-label="Walkthrough Videos">
    <div class="xra-walkthrough-gallery" data-xra-walkthrough-gallery data-xra-device-mode="<?php echo esc_attr($device_mode); ?>">
        <script type="application/json" data-xra-walkthrough-manifest><?php echo wp_json_encode($walkthrough_manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

        <article class="xra-walkthrough-player" id="xra-walkthrough-player" data-xra-walkthrough-player>
            <div class="xra-walkthrough-player__screen">
                <video
                    class="xra-walkthrough-player__video"
                    data-xra-walkthrough-video
                    controls
                    controlslist="nodownload noplaybackrate noremoteplayback"
                    disablepictureinpicture
                    playsinline
                    preload="metadata"
                    poster="<?php echo esc_attr((string) ($initial_media['poster'] ?? '')); ?>"
                    aria-label="<?php echo esc_attr($initial_title); ?>"
                >
                    <track
                        data-xra-walkthrough-track
                        kind="captions"
                        label="English"
                        srclang="en"
                        default
                        src="<?php echo esc_url((string) ($initial_media['captions'] ?? '')); ?>"
                    >
                    <source
                        data-xra-walkthrough-source
                        src="<?php echo esc_url((string) ($initial_media['src'] ?? '')); ?>"
                        type="video/mp4"
                    >
                    Your browser does not support the video element.
                </video>
            </div>
            <div class="xra-sr-only" data-xra-walkthrough-live aria-live="polite" aria-atomic="true"></div>
        </article>

        <nav class="xra-walkthrough-player__controls" aria-label="Walkthrough Player Controls">
            <button type="button" class="xra-button xra-button--secondary" data-xra-walkthrough-prev aria-controls="xra-walkthrough-player">Previous</button>
            <button type="button" class="xra-button xra-button--secondary" data-xra-walkthrough-toggle aria-controls="xra-walkthrough-player">Play</button>
            <span class="xra-walkthrough-player__counter" data-xra-walkthrough-counter>1 / 2</span>
            <button type="button" class="xra-button xra-button--secondary" data-xra-walkthrough-next aria-controls="xra-walkthrough-player">Next</button>
        </nav>

        <nav class="xra-walkthrough-strip" aria-label="Walkthrough Video Selection">
            <?php foreach ($walkthrough_items as $index => $item) : ?>
                <?php $is_active = $index === 0; ?>
                <button
                    type="button"
                    class="xra-walkthrough-strip__button<?php echo $is_active ? ' is-active' : ''; ?>"
                    data-xra-walkthrough-index="<?php echo esc_attr((string) $index); ?>"
                    aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
                >
                    <strong><?php echo esc_html((string) ($item['title'] ?? 'Walkthrough')); ?></strong>
                </button>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
