(function (global) {
    'use strict';

    var STORAGE_ENABLED = 'alcros_voice_enabled';
    var STORAGE_LAST = 'alcros_voice_last';
    var SILENT_AUDIO_SRC = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA';

    var enabled = sessionStorage.getItem(STORAGE_ENABLED) === '1';
    var lastAnnounced = sessionStorage.getItem(STORAGE_LAST) || '';
    var speechQueue = [];
    var speaking = false;
    var voicesReady = false;
    var resumeTimer = null;
    var pendingServing = null;
    var keepAliveAudio = null;
    var audioContext = null;

    function isSupported() {
        return 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined';
    }

    function isQueueDisplayPage() {
        return !!(document.body && document.body.dataset.realtime === 'queue-display');
    }

    function allowBackgroundSpeech() {
        return isQueueDisplayPage();
    }

    function formatTicketForSpeech(ticket) {
        return String(ticket || '')
            .replace(/([A-Za-z]+)(\d+)/, function (_, letters, digits) {
                return letters + ' ' + digits.split('').join(' ');
            });
    }

    function formatMessage(ticket, tableNum) {
        var ticketSpeech = formatTicketForSpeech(ticket);
        var tableSpeech = tableNum || 1;
        return 'Now serving ticket ' + ticketSpeech + '. Please proceed to table ' + tableSpeech + '.';
    }

    function pickVoice() {
        var voices = window.speechSynthesis.getVoices();
        if (!voices.length) return null;
        var preferred = voices.find(function (v) {
            return v.lang === 'en-PH' || v.lang === 'en-US' || v.lang === 'en-GB';
        });
        return preferred || voices.find(function (v) { return v.lang.indexOf('en') === 0; }) || voices[0];
    }

    function ensureVoicesLoaded(callback) {
        if (!isSupported()) return;
        var voices = window.speechSynthesis.getVoices();
        if (voices.length) {
            voicesReady = true;
            if (callback) callback();
            return;
        }
        window.speechSynthesis.addEventListener('voiceschanged', function onVoices() {
            window.speechSynthesis.removeEventListener('voiceschanged', onVoices);
            voicesReady = true;
            if (callback) callback();
        });
        window.speechSynthesis.getVoices();
    }

    function unlockAudioStack() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                if (!audioContext) {
                    audioContext = new Ctx();
                }
                if (audioContext.state === 'suspended') {
                    audioContext.resume();
                }
                var buffer = audioContext.createBuffer(1, 1, 22050);
                var source = audioContext.createBufferSource();
                source.buffer = buffer;
                source.connect(audioContext.destination);
                source.start(0);
            }
        } catch (e) {
            /* ignore */
        }

        startAudioKeepAlive();
        requestDisplayNotifications();
    }

    function startAudioKeepAlive() {
        if (!allowBackgroundSpeech()) {
            return;
        }

        if (!keepAliveAudio) {
            keepAliveAudio = document.createElement('audio');
            keepAliveAudio.id = 'alcros-voice-keepalive';
            keepAliveAudio.src = SILENT_AUDIO_SRC;
            keepAliveAudio.loop = true;
            keepAliveAudio.volume = 0.01;
            keepAliveAudio.setAttribute('playsinline', 'playsinline');
            keepAliveAudio.preload = 'auto';
            document.body.appendChild(keepAliveAudio);
        }

        keepAliveAudio.play().catch(function () {
            /* needs user gesture */
        });
    }

    function stopAudioKeepAlive() {
        if (!keepAliveAudio) {
            return;
        }
        try {
            keepAliveAudio.pause();
            keepAliveAudio.removeAttribute('src');
            keepAliveAudio.load();
            keepAliveAudio.remove();
        } catch (e) {
            /* ignore */
        }
        keepAliveAudio = null;
    }

    function playAttentionChime() {
        if (!allowBackgroundSpeech()) {
            return;
        }

        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!audioContext && Ctx) {
                audioContext = new Ctx();
            }
            if (audioContext) {
                if (audioContext.state === 'suspended') {
                    audioContext.resume();
                }
                var osc = audioContext.createOscillator();
                var gain = audioContext.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.value = 0.2;
                osc.connect(gain);
                gain.connect(audioContext.destination);
                osc.start();
                osc.stop(audioContext.currentTime + 0.25);
            }
        } catch (e) {
            /* ignore */
        }
    }

    function requestDisplayNotifications() {
        if (!allowBackgroundSpeech() || !('Notification' in window)) {
            return;
        }
        if (Notification.permission === 'default') {
            Notification.requestPermission().catch(function () { /* ignore */ });
        }
    }

    function showQueueNotification(title, body) {
        if (!allowBackgroundSpeech() || !('Notification' in window)) {
            return;
        }
        if (Notification.permission !== 'granted') {
            return;
        }
        try {
            new Notification(title, {
                body: body,
                tag: 'alcros-queue-call',
                renotify: true,
                silent: false
            });
        } catch (e) {
            /* ignore */
        }
    }

    function resumeSpeechEngine() {
        if (!window.speechSynthesis) {
            return;
        }
        try {
            window.speechSynthesis.resume();
        } catch (e) {
            /* ignore */
        }
    }

    function startResumeHack() {
        if (resumeTimer) {
            return;
        }
        var intervalMs = allowBackgroundSpeech() ? 500 : 10000;
        resumeTimer = setInterval(function () {
            if (!enabled) {
                return;
            }
            resumeSpeechEngine();
            if (allowBackgroundSpeech()) {
                startAudioKeepAlive();
            }
        }, intervalMs);
    }

    function stopResumeHack() {
        if (!resumeTimer) {
            return;
        }
        clearInterval(resumeTimer);
        resumeTimer = null;
    }

    function setEnabled(value) {
        enabled = value;
        sessionStorage.setItem(STORAGE_ENABLED, value ? '1' : '0');
        if (value) {
            startResumeHack();
            document.dispatchEvent(new CustomEvent('alcros-voice-enabled'));
        } else {
            stopResumeHack();
            stopAudioKeepAlive();
        }
        updateUI();
    }

    function enableVoiceFromUserGesture() {
        unlockAudioStack();
        clearBaseline();
        setEnabled(true);
    }

    function flashAnnouncing() {
        var el = document.getElementById('display-serving');
        if (!el) return;
        el.classList.add('ring-4', 'ring-blue-500/50');
        setTimeout(function () {
            el.classList.remove('ring-4', 'ring-blue-500/50');
        }, 1200);
    }

    function normalizeQueueItem(item) {
        if (typeof item === 'string') {
            return { text: item, callback: null, meta: null };
        }
        return {
            text: item && item.text ? item.text : '',
            callback: item && item.callback ? item.callback : null,
            meta: item && item.meta ? item.meta : null
        };
    }

    function shouldDeferSpeechForHiddenTab() {
        return document.hidden && !allowBackgroundSpeech();
    }

    function speak(text) {
        speakWithCallback(text, null);
    }

    function speakWithCallback(text, callback, meta) {
        if (!enabled || !isSupported() || !text) {
            if (callback) callback(false);
            return;
        }

        if (shouldDeferSpeechForHiddenTab()) {
            speechQueue = [{ text: text, callback: callback, meta: meta }];
            return;
        }

        ensureVoicesLoaded(function () {
            speechQueue.push({ text: text, callback: callback, meta: meta || null });
            processQueue();
        });
    }

    function flushPendingServing() {
        if (!pendingServing || shouldDeferSpeechForHiddenTab()) {
            return;
        }
        var serving = pendingServing;
        pendingServing = null;
        var key = servingKey(serving);
        if (!key || key === lastAnnounced) return;
        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
        speak(formatMessage(serving.ticket_number, serving.window_number));
    }

    function flushSpeechQueueIfReady() {
        if (!enabled || speaking || !speechQueue.length || shouldDeferSpeechForHiddenTab()) {
            return;
        }
        ensureVoicesLoaded(function () {
            processQueue();
        });
    }

    function onTabVisible() {
        resumeSpeechEngine();
        startAudioKeepAlive();
        flushSpeechQueueIfReady();
        flushPendingServing();
    }

    function finishSpeechItem(callback, success) {
        speaking = false;
        if (callback) callback(success);
        processQueue();
    }

    function processQueue() {
        if (speaking || !speechQueue.length || shouldDeferSpeechForHiddenTab()) {
            return;
        }

        speaking = true;
        var item = normalizeQueueItem(speechQueue.shift());
        var text = item.text;
        var callback = item.callback;
        var meta = item.meta;

        resumeSpeechEngine();
        startAudioKeepAlive();

        if (meta && meta.chime !== false) {
            playAttentionChime();
        }

        if (document.hidden && allowBackgroundSpeech() && meta && meta.notificationTitle) {
            showQueueNotification(meta.notificationTitle, text);
        }

        var utterance = new SpeechSynthesisUtterance(text);
        var voice = pickVoice();
        if (voice) {
            utterance.voice = voice;
            utterance.lang = voice.lang;
        } else {
            utterance.lang = 'en-US';
        }
        utterance.rate = 0.92;
        utterance.pitch = 1;
        utterance.volume = 1;

        var finished = false;
        function done(success) {
            if (finished) return;
            finished = true;
            clearTimeout(startWatchdog);
            clearTimeout(maxWatchdog);
            finishSpeechItem(callback, success);
        }

        var startWatchdog = setTimeout(function () {
            if (allowBackgroundSpeech() && document.hidden && !window.speechSynthesis.speaking) {
                done(true);
            }
        }, 2200);

        var maxWatchdog = setTimeout(function () {
            done(true);
        }, 15000);

        utterance.onstart = function () {
            clearTimeout(startWatchdog);
            flashAnnouncing();
            startResumeHack();
            resumeSpeechEngine();
        };
        utterance.onend = function () {
            done(true);
        };
        utterance.onerror = function () {
            if (allowBackgroundSpeech() && document.hidden) {
                done(true);
                return;
            }
            done(false);
        };

        window.speechSynthesis.speak(utterance);
        resumeSpeechEngine();
    }

    function servingKey(serving) {
        if (!serving || !serving.ticket_number) return '';
        if (serving.announcement_id) {
            return 'ann:' + serving.announcement_id;
        }
        if (serving.called_at) {
            return serving.ticket_number + '@' + serving.called_at;
        }
        return serving.ticket_number + '@' + (serving.window_number || 1);
    }

    function announcementKey(announcement) {
        if (!announcement || !announcement.id) return '';
        return 'ann:' + announcement.id + '@' + (announcement.started_at || announcement.requested_at || '');
    }

    function syncBaseline(serving) {
        var key = servingKey(serving);
        if (!key) return;
        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
    }

    function syncAnnouncementBaseline(announcement) {
        var key = announcementKey(announcement);
        if (!key) return;
        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
    }

    function clearBaseline() {
        lastAnnounced = '';
        sessionStorage.removeItem(STORAGE_LAST);
    }

    function announceIfNew(serving) {
        if (!enabled || !isSupported()) return;
        var key = servingKey(serving);
        if (!key || key === lastAnnounced) return;

        if (shouldDeferSpeechForHiddenTab()) {
            pendingServing = serving;
            return;
        }

        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
        speak(formatMessage(serving.ticket_number, serving.window_number));
    }

    function announceAnnouncement(announcement, onComplete) {
        if (!enabled || !isSupported() || !announcement) {
            if (onComplete) onComplete(false);
            return;
        }

        var key = announcementKey(announcement);
        if (!key) {
            if (onComplete) onComplete(false);
            return;
        }

        unlockAudioStack();
        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);

        var message = formatMessage(announcement.ticket_number, announcement.window_number);
        var ticketLabel = String(announcement.ticket_number || '');

        speakWithCallback(message, onComplete || null, {
            chime: true,
            notificationTitle: 'Now calling ' + ticketLabel
        });
    }

    function updateUI() {
        var overlay = document.getElementById('voice-enable-overlay');
        var status = document.getElementById('voice-status');
        var toggle = document.getElementById('voice-toggle-btn');
        var supported = isSupported();

        if (overlay) {
            overlay.classList.toggle('hidden', !supported || enabled);
        }
        if (status) {
            if (!supported) {
                status.textContent = 'Voice unavailable';
                status.className = 'text-[9px] font-bold uppercase tracking-wider text-red-400';
            } else if (enabled) {
                status.textContent = allowBackgroundSpeech() ? 'Voice on · works in background' : 'Voice on';
                status.className = 'text-[9px] font-bold uppercase tracking-wider text-green-400';
            } else {
                status.textContent = 'Voice off';
                status.className = 'text-[9px] font-bold uppercase tracking-wider text-gray-500';
            }
        }
        if (toggle) {
            toggle.textContent = enabled ? 'Mute voice' : 'Enable voice';
            toggle.classList.toggle('bg-green-600', enabled);
            toggle.classList.toggle('bg-gray-700', !enabled);
        }
    }

    function initVoiceUI() {
        if (!isSupported()) {
            updateUI();
            return;
        }

        ensureVoicesLoaded();

        if (enabled && allowBackgroundSpeech()) {
            startResumeHack();
        }

        var enableBtn = document.getElementById('voice-enable-btn');
        var toggle = document.getElementById('voice-toggle-btn');
        var testBtn = document.getElementById('voice-test-btn');

        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                enableVoiceFromUserGesture();
                speakWithCallback('Voice announcements are now enabled.', function () {
                    document.dispatchEvent(new CustomEvent('alcros-voice-ready'));
                });
            });
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (enabled) {
                    window.speechSynthesis.cancel();
                    speechQueue = [];
                    speaking = false;
                    setEnabled(false);
                } else {
                    enableVoiceFromUserGesture();
                    speakWithCallback('Voice announcements are now enabled.', function () {
                        document.dispatchEvent(new CustomEvent('alcros-voice-ready'));
                    });
                }
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                if (!enabled) {
                    enableVoiceFromUserGesture();
                }
                speak('Now serving ticket Q 0 0 1. Please proceed to window 1.');
            });
        }

        updateUI();
    }

    global.AlcrosVoice = {
        isSupported: isSupported,
        isEnabled: function () { return enabled; },
        isSpeaking: function () { return speaking || (window.speechSynthesis && window.speechSynthesis.speaking); },
        syncBaseline: syncBaseline,
        syncAnnouncementBaseline: syncAnnouncementBaseline,
        clearBaseline: clearBaseline,
        announceIfNew: announceIfNew,
        announceAnnouncement: announceAnnouncement,
        speak: speak,
        init: initVoiceUI
    };

    document.addEventListener('DOMContentLoaded', initVoiceUI);
    document.addEventListener('visibilitychange', onTabVisible);
})(window);
