(function (global) {
    'use strict';

    function buildAnnouncementUrl() {
        if (global.AlcrosPoll && AlcrosPoll.buildUrl) {
            return AlcrosPoll.buildUrl('api/queue_announcement.php');
        }
        return 'api/queue_announcement.php';
    }

    function postAnnouncementAction(action, payload) {
        var body = new FormData();
        body.append('action', action);
        if (payload && payload.id) {
            body.append('id', String(payload.id));
        }
        return fetch(buildAnnouncementUrl(), {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        });
    }

    function readAnnouncementSnapshot(data) {
        if (!data) return null;
        if (data.display && data.display.announcement) {
            return data.display.announcement;
        }
        if (data.announcement) {
            return data.announcement;
        }
        return null;
    }

    function createQueueAnnouncementProcessor() {
        var announcementBusy = false;
        var currentAnnouncementId = null;
        var retryCounts = {};

        function voiceEnabled() {
            return global.AlcrosVoice && AlcrosVoice.isEnabled();
        }

        function voiceSpeaking() {
            return global.AlcrosVoice && AlcrosVoice.isSpeaking && AlcrosVoice.isSpeaking();
        }

        function completeAnnouncement(id) {
            return postAnnouncementAction('complete', { id: id }).then(function () {
                currentAnnouncementId = null;
                announcementBusy = false;
            }).catch(function () {
                currentAnnouncementId = null;
                announcementBusy = false;
            });
        }

        function claimNextAnnouncement() {
            if (!voiceEnabled() || announcementBusy || voiceSpeaking()) {
                return;
            }

            postAnnouncementAction('claim').then(function (result) {
                if (!result || !result.ok) {
                    return;
                }
                if (result.announcement) {
                    playAnnouncement(result.announcement);
                }
            }).catch(function () {
                /* ignore */
            });
        }

        function playAnnouncement(announcement) {
            if (!announcement || !announcement.id || !voiceEnabled()) {
                return;
            }

            if (announcementBusy || voiceSpeaking()) {
                return;
            }

            currentAnnouncementId = announcement.id;
            announcementBusy = true;

            AlcrosVoice.announceAnnouncement(announcement, function (spoken) {
                var id = String(announcement.id);
                if (!spoken) {
                    retryCounts[id] = (retryCounts[id] || 0) + 1;
                    announcementBusy = false;
                    if (retryCounts[id] >= 4) {
                        delete retryCounts[id];
                        completeAnnouncement(announcement.id).then(function () {
                            claimNextAnnouncement();
                        });
                        return;
                    }
                    setTimeout(function () {
                        playAnnouncement(announcement);
                    }, 1500);
                    return;
                }
                delete retryCounts[id];
                completeAnnouncement(announcement.id).then(function () {
                    claimNextAnnouncement();
                });
            });
        }

        function process(data) {
            if (!voiceEnabled() || announcementBusy || voiceSpeaking()) {
                return;
            }

            var ann = readAnnouncementSnapshot(data);
            if (!ann) {
                currentAnnouncementId = null;
                return;
            }

            if (ann.active) {
                playAnnouncement(ann.active);
                return;
            }

            if (ann.pending_count > 0) {
                claimNextAnnouncement();
                return;
            }

            currentAnnouncementId = null;
        }

        function startWhenReady() {
            if (!voiceEnabled() || announcementBusy || voiceSpeaking()) {
                return;
            }
            claimNextAnnouncement();
        }

        function bindVoiceEvents() {
            document.addEventListener('alcros-voice-enabled', function () {
                setTimeout(startWhenReady, 400);
            });
            document.addEventListener('alcros-voice-ready', startWhenReady);
            setTimeout(function () {
                if (voiceEnabled()) {
                    startWhenReady();
                }
            }, 800);
        }

        return {
            process: process,
            startWhenReady: startWhenReady,
            bindVoiceEvents: bindVoiceEvents
        };
    }

    global.AlcrosQueueAnnouncements = {
        createProcessor: createQueueAnnouncementProcessor,
        readSnapshot: readAnnouncementSnapshot
    };
})(window);
