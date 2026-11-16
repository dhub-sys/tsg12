<?php
/**
 * «Οι εγγυήσεις μου» sign-in timer, fixed at the corner of the screen (portal and activation pages):
 * time left, ↻ to renew, red in the last minute. Optional $tw_pill = ['left' => seconds, 'csrf' => token].
 */
if (!defined('ABSPATH')) exit;
$tw_pill = isset($tw_pill) && is_array($tw_pill) ? $tw_pill : [];
?>
<div class="tw-session-pill" data-portal-timer hidden role="status" aria-live="off"<?php if ($tw_pill) : ?> data-left-init="<?php echo (int) $tw_pill['left']; ?>" data-csrf="<?php echo esc_attr($tw_pill['csrf']); ?>"<?php endif; ?>>
    <span class="tw-session-ico" aria-hidden="true">⏱</span>
    <span><?php tw_e('Σύνδεση:'); ?> <strong data-left>–</strong></span>
    <button type="button" data-action="session-refresh" title="<?php echo esc_attr(tw__('Ανανέωση σύνδεσης')); ?>" aria-label="<?php echo esc_attr(tw__('Ανανέωση σύνδεσης')); ?>">↻</button>
</div>
