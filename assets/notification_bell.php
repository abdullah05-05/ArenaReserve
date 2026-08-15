<?php
/**
 * assets/notification_bell.php
 * Reusable notification bell icon + dropdown panel.
 *
 * USAGE: Include this file inside the header's right-side actions div,
 * replacing the old static bell button.
 *
 * Requires: $pdo, $_SESSION['user_id'] to be set before inclusion.
 */
?>
<!-- ════════════════════════════════
     NOTIFICATION BELL STYLES
════════════════════════════════ -->
<style>
/* Bell wrapper */
#notif-bell-btn {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #64748b;
    transition: background 0.18s, color 0.18s;
    outline: none;
    flex-shrink: 0;
}
#notif-bell-btn:hover {
    background: #f1f5f9;
    color: #10b981;
}
#notif-bell-btn svg {
    width: 22px;
    height: 22px;
    transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
}
#notif-bell-btn:hover svg,
#notif-bell-btn.open svg {
    transform: rotate(-15deg);
}

/* Unread badge */
#notif-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    min-width: 17px;
    height: 17px;
    background: #ef4444;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #fff;
    pointer-events: none;
    transform: scale(0);
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1);
    font-family: 'Inter', sans-serif;
}
#notif-badge.visible {
    transform: scale(1);
}
@keyframes bell-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
    70%  { box-shadow: 0 0 0 7px rgba(239,68,68,0); }
    100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
}
#notif-badge.pulse {
    animation: bell-pulse 1.8s infinite;
}

/* Dropdown panel */
#notif-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 10px);
    width: 360px;
    max-width: calc(100vw - 24px);
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.12), 0 4px 16px rgba(0,0,0,.06);
    z-index: 9999;
    overflow: hidden;
    transform-origin: top right;
    transform: scale(0.92) translateY(-8px);
    opacity: 0;
    pointer-events: none;
    transition: transform 0.22s cubic-bezier(.34,1.36,.64,1), opacity 0.18s ease;
}
#notif-dropdown.open {
    transform: scale(1) translateY(0);
    opacity: 1;
    pointer-events: all;
}

/* Header */
.notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px 12px;
    border-bottom: 1px solid #f1f5f9;
}
.notif-header-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    font-family: 'Inter', sans-serif;
}
.notif-mark-all {
    font-size: 11px;
    font-weight: 600;
    color: #10b981;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.15s;
    font-family: 'Inter', sans-serif;
}
.notif-mark-all:hover { background: #f0fdf4; }

/* List */
#notif-list {
    max-height: 380px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #e2e8f0 transparent;
}
#notif-list::-webkit-scrollbar { width: 4px; }
#notif-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

/* Individual item */
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 18px;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid #f8fafc;
    text-decoration: none;
    color: inherit;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: #f8fafc; }
.notif-item.unread { background: #f0fdf4; }
.notif-item.unread:hover { background: #dcfce7; }

/* Icon circle */
.notif-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    margin-top: 2px;
}
.notif-icon.green  { background: #f0fdf4; }
.notif-icon.red    { background: #fef2f2; }
.notif-icon.blue   { background: #eff6ff; }
.notif-icon.amber  { background: #fffbeb; }
.notif-icon.purple { background: #faf5ff; }

/* Text */
.notif-text { flex: 1; min-width: 0; }
.notif-title {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    font-family: 'Inter', sans-serif;
    margin: 0 0 3px;
    line-height: 1.3;
}
.notif-msg {
    font-size: 12px;
    color: #64748b;
    font-family: 'Inter', sans-serif;
    margin: 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.notif-time {
    font-size: 10px;
    color: #94a3b8;
    font-family: 'Inter', sans-serif;
    margin-top: 5px;
    display: block;
}
.notif-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    flex-shrink: 0;
    margin-top: 6px;
}

/* Empty state */
.notif-empty {
    padding: 40px 20px;
    text-align: center;
}
.notif-empty svg { color: #cbd5e1; margin-bottom: 12px; }
.notif-empty p { color: #94a3b8; font-size: 13px; font-family: 'Inter', sans-serif; margin: 0; }

/* Loading skeleton */
.notif-skeleton {
    padding: 13px 18px;
    display: flex;
    gap: 12px;
    align-items: center;
    border-bottom: 1px solid #f8fafc;
}
.skel-circle {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    flex-shrink: 0;
}
.skel-line {
    height: 10px; border-radius: 5px;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    margin-bottom: 6px;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
</style>

<!-- ════════════════════════════════
     BELL BUTTON + DROPDOWN
════════════════════════════════ -->
<div class="relative" id="notif-wrapper">
    <button id="notif-bell-btn" onclick="toggleNotifDropdown()" title="Notifications" aria-label="Notifications">
        <!-- Bell SVG -->
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <!-- Badge -->
        <span id="notif-badge" aria-label="Unread notifications">0</span>
    </button>

    <!-- Dropdown -->
    <div id="notif-dropdown" role="dialog" aria-label="Notifications panel">
        <!-- Phone & Browser Push Banner -->
        <div id="push-permission-banner" style="display:none;background:#f0fdf4;border-bottom:1px solid #bbf7d0;padding:10px 14px;align-items:center;justify-content:space-between;gap:8px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:16px;">📲</span>
                <span style="font-size:11px;color:#166534;font-weight:600;line-height:1.3;">Enable phone & browser push alerts</span>
            </div>
            <button onclick="requestPushPermission(this)" style="font-size:10px;font-weight:700;background:#10b981;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;flex-shrink:0;transition:opacity 0.15s;" onmouseover="this.style.opacity=0.9" onmouseout="this.style.opacity=1">Enable</button>
        </div>

        <div class="notif-header">
            <span class="notif-header-title">🔔 Notifications</span>
            <button class="notif-mark-all" onclick="markAllRead()" id="notif-mark-all-btn" style="display:none;">Mark all read</button>
        </div>
        <div id="notif-list">
            <!-- Skeleton loader -->
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="notif-skeleton">
                <div class="skel-circle"></div>
                <div style="flex:1;">
                    <div class="skel-line" style="width:70%;"></div>
                    <div class="skel-line" style="width:90%;"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════
     NOTIFICATION JAVASCRIPT & WEB PUSH
════════════════════════════════ -->
<script>
(function () {
    const TYPE_ICONS = {
        booking_confirmed:   { emoji: '✅', cls: 'green'  },
        booking_cancelled:   { emoji: '❌', cls: 'red'    },
        challenge_accepted:  { emoji: '⚡', cls: 'amber'  },
        challenge_posted:    { emoji: '🏟️', cls: 'blue'   },
        challenge_received:  { emoji: '🤝', cls: 'purple' },
        challenge_cancelled: { emoji: '⚠️', cls: 'amber'  },
        venue_approved:      { emoji: '✅', cls: 'green'  },
        venue_rejected:      { emoji: '❌', cls: 'red'    },
        deposit_approved:    { emoji: '💰', cls: 'green'  },
        deposit_rejected:    { emoji: '❌', cls: 'red'    },
        new_booking_owner:   { emoji: '🏟️', cls: 'blue'   },
        default:             { emoji: '🔔', cls: 'blue'   },
    };

    let dropdownOpen     = false;
    let notifLoaded      = false;
    let pollInterval     = null;
    let swRegistration   = null;
    let lastSeenNotifId  = parseInt(localStorage.getItem('ar_last_notif_id') || '0');

    // ── Register Service Worker for Mobile / Browser Push ──
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').then(reg => {
            swRegistration = reg;
        }).catch(() => {});
    }

    // ── Push Permission Prompt Banner Check ───────────────
    function checkPushBanner() {
        const banner = document.getElementById('push-permission-banner');
        if (!banner) return;
        if ('Notification' in window && Notification.permission === 'default') {
            banner.style.display = 'flex';
        } else {
            banner.style.display = 'none';
        }
    }

    window.requestPushPermission = function (btn) {
        if (!('Notification' in window)) {
            alert('Browser notifications are not supported on this browser.');
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Enabling…'; }

        Notification.requestPermission().then(permission => {
            checkPushBanner();
            if (permission === 'granted') {
                dispatchNativeNotification(
                    'Notifications Enabled! 🎉',
                    'You will now receive alerts for challenges, bookings & updates on this device.',
                    'explore.php'
                );
            }
        });
    };

    // ── Dispatch Native System / Mobile Push Notification ──
    function dispatchNativeNotification(title, message, link) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;

        const options = {
            body: message || '',
            vibrate: [200, 100, 200],
            data: { link: link || 'explore.php' },
            tag: 'ar-alert-' + Date.now()
        };

        if (swRegistration && swRegistration.showNotification) {
            swRegistration.showNotification(title, options);
        } else {
            try {
                const notif = new Notification(title, options);
                notif.onclick = function () {
                    window.focus();
                    if (link) window.location.href = link;
                };
            } catch (e) {}
        }
    }

    window.toggleNotifDropdown = function () {
        const dd  = document.getElementById('notif-dropdown');
        const btn = document.getElementById('notif-bell-btn');
        dropdownOpen = !dropdownOpen;
        dd.classList.toggle('open', dropdownOpen);
        btn.classList.toggle('open', dropdownOpen);

        if (dropdownOpen) {
            checkPushBanner();
            if (!notifLoaded) loadNotifications();
        }
    };

    // Close on outside click
    document.addEventListener('click', function (e) {
        if (!document.getElementById('notif-wrapper').contains(e.target) && dropdownOpen) {
            dropdownOpen = false;
            document.getElementById('notif-dropdown').classList.remove('open');
            document.getElementById('notif-bell-btn').classList.remove('open');
        }
    });

    // ── Load / render ─────────────────────────────────────
    function loadNotifications() {
        fetch('get_notifications.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                notifLoaded = true;
                renderBadge(data.unread_count);
                renderList(data.notifications, data.unread_count);

                // Update latest seen ID
                if (data.notifications && data.notifications.length > 0) {
                    const maxId = Math.max(...data.notifications.map(n => parseInt(n.id)));
                    if (maxId > lastSeenNotifId) {
                        lastSeenNotifId = maxId;
                        localStorage.setItem('ar_last_notif_id', String(maxId));
                    }
                }
            })
            .catch(() => {});
    }

    function renderBadge(count) {
        const badge = document.getElementById('notif-badge');
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.add('visible', 'pulse');
        } else {
            badge.classList.remove('visible', 'pulse');
        }
    }

    function renderList(items, unreadCount) {
        const list = document.getElementById('notif-list');
        const markBtn = document.getElementById('notif-mark-all-btn');

        if (unreadCount > 0) markBtn.style.display = '';
        else markBtn.style.display = 'none';

        if (!items || items.length === 0) {
            list.innerHTML = `
                <div class="notif-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin:0 auto 12px;display:block;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <p>You're all caught up!<br><span style="color:#cbd5e1;">No notifications yet.</span></p>
                </div>`;
            return;
        }

        list.innerHTML = items.map(n => {
            const icon  = TYPE_ICONS[n.type] || TYPE_ICONS.default;
            const tag   = n.link ? 'a' : 'div';
            const href  = n.link ? `href="${escHtml(n.link)}"` : '';
            const unread = !n.is_read ? 'unread' : '';
            return `
            <${tag} class="notif-item ${unread}" ${href}
                    onclick="markRead(${n.id}, this)" data-id="${n.id}">
                <div class="notif-icon ${icon.cls}">${icon.emoji}</div>
                <div class="notif-text">
                    <p class="notif-title">${escHtml(n.title)}</p>
                    <p class="notif-msg">${escHtml(n.message)}</p>
                    <span class="notif-time">${escHtml(n.time_ago)}</span>
                </div>
                ${!n.is_read ? '<div class="notif-dot"></div>' : ''}
            </${tag}>`;
        }).join('');
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    // ── Mark single read ──────────────────────────────────
    window.markRead = function (id, el) {
        if (el.classList.contains('unread')) {
            el.classList.remove('unread');
            const dot = el.querySelector('.notif-dot');
            if (dot) dot.remove();

            fetch('mark_notifications_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + id,
            }).then(() => refreshBadge());
        }
    };

    // ── Mark all read ─────────────────────────────────────
    window.markAllRead = function () {
        fetch('mark_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'notification_id=0',
        }).then(() => {
            document.querySelectorAll('.notif-item.unread').forEach(el => {
                el.classList.remove('unread');
                const dot = el.querySelector('.notif-dot');
                if (dot) dot.remove();
            });
            document.getElementById('notif-mark-all-btn').style.display = 'none';
            renderBadge(0);
        });
    };

    // ── Refresh badge & Trigger Native Push on New Alert ──
    function refreshBadge() {
        fetch('get_notifications.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                renderBadge(data.unread_count);

                if (data.notifications && data.notifications.length > 0) {
                    const newest = data.notifications[0];
                    const newestId = parseInt(newest.id);

                    // If user has enabled push and a new unread notification arrived
                    if (lastSeenNotifId > 0 && newestId > lastSeenNotifId && !newest.is_read) {
                        dispatchNativeNotification(
                            newest.title || 'ArenaReserve Alert 🔔',
                            newest.message || 'You have a new update.',
                            newest.link
                        );
                    }

                    if (newestId > lastSeenNotifId) {
                        lastSeenNotifId = newestId;
                        localStorage.setItem('ar_last_notif_id', String(newestId));
                    }
                }
            })
            .catch(() => {});
    }

    // Initial badge load + check push status
    refreshBadge();
    checkPushBanner();

    // Poll every 25s for background updates
    pollInterval = setInterval(refreshBadge, 25000);
})();
</script>
