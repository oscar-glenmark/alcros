(function (global) {
    'use strict';

    var STORAGE_ENABLED = 'alcros_voice_enabled';
    var STORAGE_LAST = 'alcros_voice_last';

    var enabled = sessionStorage.getItem(STORAGE_ENABLED) === '1';
    var lastAnnounced = sessionStorage.getItem(STORAGE_LAST) || '';
    var speechQueue = [];
    var speaking = false;
    var voicesReady = false;
    var resumeTimer = null;
    var pendingServing = null;

    function isSupported() {
        return 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined';
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

    function startResumeHack() {
        if (resumeTimer) return;
        resumeTimer = setInterval(function () {
            if (window.speechSynthesis.speaking) {
                window.speechSynthesis.resume();
            }
        }, 10000);
    }

    function setEnabled(value) {
        enabled = value;
        sessionStorage.setItem(STORAGE_ENABLED, value ? '1' : '0');
        updateUI();
    }

    function flashAnnouncing() {
        var el = document.getElementById('display-serving');
        if (!el) return;
        el.classList.add('ring-4', 'ring-blue-500/50');
        setTimeout(function () {
            el.classList.remove('ring-4', 'ring-blue-500/50');
        }, 1200);
    }

    function speak(text) {
        if (!enabled || !isSupported() || !text) return;

        if (document.hidden) {
            speechQueue = [text];
            return;
        }

        ensureVoicesLoaded(function () {
            speechQueue.push(text);
            processQueue();
        });
    }

    function flushPendingServing() {
        if (!pendingServing || document.hidden) return;
        var serving = pendingServing;
        pendingServing = null;
        var key = servingKey(serving);
        if (!key || key === lastAnnounced) return;
        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
        speak(formatMessage(serving.ticket_number, serving.window_number));
    }

    function onTabVisible() {
        if (document.hidden) return;
        if (window.speechSynthesis) {
            window.speechSynthesis.resume();
        }
        if (speechQueue.length && !speaking) {
            ensureVoicesLoaded(function () {
                processQueue();
            });
        }
        flushPendingServing();
    }

    function processQueue() {
        if (speaking || !speechQueue.length) return;
        speaking = true;
        var text = speechQueue.shift();

        window.speechSynthesis.cancel();

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

        utterance.onstart = function () {
            flashAnnouncing();
            startResumeHack();
        };
        utterance.onend = function () {
            speaking = false;
            processQueue();
        };
        utterance.onerror = function () {
            speaking = false;
            processQueue();
        };

        window.speechSynthesis.speak(utterance);
        window.speechSynthesis.resume();
    }

    function servingKey(serving) {
        if (!serving || !serving.ticket_number) return '';
        if (serving.called_at) {
            return serving.ticket_number + '@' + serving.called_at;
        }
        return serving.ticket_number + '@' + (serving.window_number || 1);
    }

    function syncBaseline(serving) {
        var key = servingKey(serving);
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

        if (document.hidden) {
            pendingServing = serving;
            return;
        }

        lastAnnounced = key;
        sessionStorage.setItem(STORAGE_LAST, key);
        speak(formatMessage(serving.ticket_number, serving.window_number));
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
                status.textContent = 'Voice on';
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

        var enableBtn = document.getElementById('voice-enable-btn');
        var toggle = document.getElementById('voice-toggle-btn');
        var testBtn = document.getElementById('voice-test-btn');

        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                setEnabled(true);
                clearBaseline();
                speak('Voice announcements are now enabled.');
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
                    setEnabled(true);
                    speak('Voice announcements are now enabled.');
                }
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                if (!enabled) setEnabled(true);
                speak('Now serving ticket Q 0 0 1. Please proceed to window 1.');
            });
        }

        updateUI();
    }

    global.AlcrosVoice = {
        isSupported: isSupported,
        isEnabled: function () { return enabled; },
        syncBaseline: syncBaseline,
        clearBaseline: clearBaseline,
        announceIfNew: announceIfNew,
        speak: speak,
        init: initVoiceUI
    };

    document.addEventListener('DOMContentLoaded', initVoiceUI);
    document.addEventListener('visibilitychange', onTabVisible);
})(window);
