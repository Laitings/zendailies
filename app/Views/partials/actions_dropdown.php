<?php

/**
 * @var array $actions Array of ['label' => '', 'link' => '', 'icon' => '', 'method' => 'GET|POST|BUTTON', 'class' => '', 'attr' => '']
 */
$__actions = $userActions ?? ($actions ?? []);
?>
<div class="zd-pro-menu"> <button type="button" class="zd-pro-trigger"> ACTIONS <span style="font-size: 8px; opacity: 0.6; margin-left: 4px;">▼</span>
    </button>
    <div class="zd-pro-content"> <?php foreach ($__actions as $action): ?>
            <?php if (($action['method'] ?? 'GET') === 'POST'): ?>
                <form method="POST" action="<?= htmlspecialchars($action['link']) ?>" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                    <button type="submit"
                        class="zd-pro-item <?= ($action['is_danger'] ?? false) ? 'is-danger' : '' ?>"
                        onclick="event.stopPropagation();">
                        <img src="/assets/icons/<?= $action['icon'] ?>.svg" class="zd-pro-icon">
                        <?= $action['label'] ?>
                    </button>
                </form>
            <?php elseif (($action['method'] ?? 'GET') === 'BUTTON'): ?>
                <button type="button"
                    class="zd-pro-item <?= !empty($action['class']) ? $action['class'] : '' ?> <?= ($action['is_danger'] ?? false) ? 'is-danger' : '' ?>"
                    <?= !empty($action['attr']) ? $action['attr'] : '' ?>
                    onclick="event.stopPropagation();">
                    <img src="/assets/icons/<?= $action['icon'] ?>.svg" class="zd-pro-icon">
                    <?= $action['label'] ?>
                </button>
            <?php else: ?>
                <a href="<?= htmlspecialchars($action['link']) ?>"
                    class="zd-pro-item <?= ($action['is_danger'] ?? false) ? 'is-danger' : '' ?>">
                    <img src="/assets/icons/<?= $action['icon'] ?>.svg" class="zd-pro-icon">
                    <?= $action['label'] ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>