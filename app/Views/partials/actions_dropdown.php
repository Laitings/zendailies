<?php

/**
 * @var array $actions Array of ['label' => '', 'link' => '', 'icon' => '', 'method' => 'GET|POST', 'is_danger' => bool]
 */
// Fallback logic to catch different variable names from different controllers
?>
<?php
$renderActions = $actions ?? ($userActions ?? ($__actions ?? []));
?>
<div class="zd-pro-menu">
    <button type="button" class="zd-pro-trigger">
        Actions
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.6;">
            <path d="M6 9l6 6 6-6" />
        </svg>
    </button>
    <div class="zd-pro-content">
        <?php foreach ($renderActions as $action): ?>
            <?php
            $method   = $action['method'] ?? 'GET';
            $isDanger = !empty($action['is_danger']);
            $icon     = !empty($action['icon']) ? $action['icon'] : 'circle';
            // Capture custom classes and attributes for your JS modals
            $customClass = $action['class'] ?? '';
            $customAttr  = $action['attr'] ?? '';
            ?>
            <?php if ($method === 'POST'): ?>
                <form method="POST" action="<?= htmlspecialchars($action['link']) ?>" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                    <button type="submit" class="zd-pro-item <?= $customClass ?> <?= $isDanger ? 'is-danger' : '' ?>" <?= $customAttr ?> onclick="event.stopPropagation();">
                        <img src="/assets/icons/<?= $icon ?>.svg" class="zd-pro-icon" alt="">
                        <?= htmlspecialchars($action['label']) ?>
                    </button>
                </form>
            <?php elseif ($method === 'BUTTON'): ?>
                <button type="button" class="zd-pro-item <?= $customClass ?> <?= $isDanger ? 'is-danger' : '' ?>" <?= $customAttr ?> onclick="event.stopPropagation();">
                    <img src="/assets/icons/<?= $icon ?>.svg" class="zd-pro-icon" alt="">
                    <?= htmlspecialchars($action['label']) ?>
                </button>
            <?php else: ?>
                <a href="<?= htmlspecialchars($action['link']) ?>" class="zd-pro-item <?= $customClass ?>" <?= $customAttr ?>>
                    <img src="/assets/icons/<?= $icon ?>.svg" class="zd-pro-icon" alt="">
                    <?= htmlspecialchars($action['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>