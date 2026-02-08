<?php

/** @var string $layout */
/** @var array $person */
$this->extend($layout);
$this->start('content');
?>

<div class="mobile-days-page" style="padding: 16px; box-sizing: border-box;">
    <div class="mobile-days-header" style="margin-bottom: 24px;">
        <h1>Profile Settings</h1>
        <div class="project-subtitle">Manage your personal information</div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border: 1px solid rgba(46, 204, 113, 0.3);">
            Changes saved successfully!
        </div>
    <?php endif; ?>

    <div class="mobile-day-card" style="display: block; padding: 20px; border: 1px solid #3aa0ff; background: #13151b; box-sizing: border-box; border-radius: 16px; overflow: hidden;">

        <form action="/account/update" method="POST" style="display: flex; flex-direction: column; gap: 20px; width: 100%;">

            <div style="width: 100%;">
                <label style="color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 8px;">Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_SESSION['account']['email'] ?? '') ?>"
                    style="width: 100%; background: #0b0c10; border: 1px solid #2a3342; color: #fff; padding: 12px; border-radius: 8px; box-sizing: border-box; font-size: 16px;">
            </div>

            <div style="width: 100%;">
                <label style="color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 8px;">First Name</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($person['first_name']) ?>"
                    style="width: 100%; background: #0b0c10; border: 1px solid #2a3342; color: #fff; padding: 12px; border-radius: 8px; box-sizing: border-box; font-size: 16px;">
            </div>

            <div style="width: 100%;">
                <label style="color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 8px;">Last Name</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($person['last_name']) ?>"
                    style="width: 100%; background: #0b0c10; border: 1px solid #2a3342; color: #fff; padding: 12px; border-radius: 8px; box-sizing: border-box; font-size: 16px;">
            </div>

            <div style="width: 100%; padding-top: 10px; border-top: 1px solid #1f232d;">
                <label style="color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 8px;">New Password</label>
                <input type="password" name="new_password" placeholder="Leave blank to keep current"
                    style="width: 100%; background: #0b0c10; border: 1px solid #2a3342; color: #fff; padding: 12px; border-radius: 8px; box-sizing: border-box; font-size: 16px;">
            </div>

            <button type="submit" style="background: #3aa0ff; color: #fff; border: none; padding: 16px; border-radius: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; width: 100%; margin-top: 10px;">
                Save Settings
            </button>
        </form>
    </div>

    <div style="margin-top: 30px; padding: 16px; border: 1px dashed #2a3342; border-radius: 12px; text-align: center;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0; line-height: 1.5;">
            <strong>Note:</strong> Advanced settings are available on the desktop version.
        </p>
    </div>
</div>

<?php $this->end(); ?>