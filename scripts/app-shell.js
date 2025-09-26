(() => {
    const doc = document;
    const body = doc.body;
    const screen = body?.dataset.screen || 'home';
    const toastEl = doc.getElementById('app-toast');
    const focusableSelector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');
    let toastTimeout = null;
    let voicesCache = [];

    const dialogController = (() => {
        let activeDialog = null;
        let lastTrigger = null;
        let keydownHandler = null;

        const trapFocus = (event) => {
            if (!activeDialog || event.key !== 'Tab') {
                return;
            }
            const focusables = Array.from(activeDialog.querySelectorAll(focusableSelector)).filter((el) => el.offsetParent !== null || activeDialog.contains(el));
            if (focusables.length === 0) {
                event.preventDefault();
                return;
            }
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && doc.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && doc.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        const close = (dialog, restoreFocus = true) => {
            if (!dialog) return;
            dialog.setAttribute('hidden', 'hidden');
            dialog.removeEventListener('keydown', keydownHandler);
            if (restoreFocus && lastTrigger) {
                lastTrigger.focus();
            }
            if (activeDialog === dialog) {
                activeDialog = null;
            }
        };

        const open = (dialog, trigger) => {
            if (!dialog) return;
            if (activeDialog && activeDialog !== dialog) {
                close(activeDialog, false);
            }
            lastTrigger = trigger || null;
            dialog.removeAttribute('hidden');
            keydownHandler = trapFocus;
            dialog.addEventListener('keydown', keydownHandler);
            const focusTarget = dialog.querySelector('.dialog-content') || dialog;
            const focusables = Array.from(dialog.querySelectorAll(focusableSelector));
            const initialFocus = focusables.find((node) => node.getAttribute('autofocus') !== null) || focusables[0] || focusTarget;
            requestAnimationFrame(() => {
                initialFocus?.focus();
            });
            activeDialog = dialog;
        };

        doc.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && activeDialog) {
                event.preventDefault();
                close(activeDialog);
            }
        });

        return {
            open,
            close
        };
    })();

    const showToast = (message, type = 'info') => {
        if (!toastEl || !message) {
            return;
        }
        toastEl.textContent = message;
        toastEl.dataset.type = type;
        toastEl.hidden = false;
        toastEl.classList.remove('toast-success', 'toast-error', 'toast-info');
        const typeClass = type === 'error' ? 'toast-error' : type === 'success' ? 'toast-success' : 'toast-info';
        toastEl.classList.add(typeClass);
        clearTimeout(toastTimeout);
        toastTimeout = window.setTimeout(() => {
            toastEl.hidden = true;
        }, 4000);
    };

    const initFlashToast = () => {
        const flash = doc.querySelector('.flash');
        if (!flash) {
            return;
        }
        const type = flash.classList.contains('error') ? 'error' : 'success';
        showToast(flash.textContent.trim(), type);
        flash.remove();
    };

    const syncSearchScreen = () => {
        const hiddenInput = doc.querySelector('input[data-search-screen]');
        if (hiddenInput && body?.dataset.screen) {
            hiddenInput.value = body.dataset.screen;
        }
    };

    const applyLanguageDirection = (lang) => {
        const uiLang = lang || window.localStorage?.getItem('ui-lang') || doc.documentElement.lang || 'en-US';
        const isRTL = /^he|ar|fa|ur/i.test(uiLang);
        doc.documentElement.lang = uiLang;
        doc.documentElement.dir = isRTL ? 'rtl' : 'ltr';
        body?.classList.toggle('rtl', isRTL);
        doc.querySelectorAll('[data-rtl-sensitive]').forEach((node) => {
            node.dir = isRTL ? 'rtl' : 'ltr';
        });
        return isRTL;
    };

    const initNavigation = () => {
        const navItems = doc.querySelectorAll('.bottom-nav-item');
        navItems.forEach((item) => {
            item.addEventListener('click', () => {
                if (item.dataset.nav) {
                    sessionStorage.setItem('hebrew-active-screen', item.dataset.nav);
                }
            });
        });
    };

    const initConfirmationHandlers = () => {
        doc.querySelectorAll('form[data-confirm-message]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const message = form.dataset.confirmMessage;
                if (!message) {
                    return;
                }
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    };

    const initDialogs = () => {
        doc.querySelectorAll('[data-dialog-open]').forEach((trigger) => {
            const id = trigger.getAttribute('data-dialog-open');
            const dialog = id ? doc.getElementById(`dialog-${id}`) : null;
            if (!dialog) {
                return;
            }
            trigger.addEventListener('click', () => {
                dialogController.open(dialog, trigger);
            });
        });

        doc.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = button.closest('.dialog');
                dialogController.close(dialog);
            });
        });

        doc.querySelectorAll('.dialog').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialogController.close(dialog);
                }
            });
        });
    };

    const initLanguagePicker = () => {
        const langButtons = doc.querySelectorAll('[data-set-ui-lang]');
        langButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const lang = button.getAttribute('data-set-ui-lang');
                if (!lang) return;
                window.localStorage?.setItem('ui-lang', lang);
                applyLanguageDirection(lang);
                showToast(lang.startsWith('he') ? 'שפת הממשק עודכנה.' : 'Interface language updated.', 'success');
            });
        });
    };

    const initAppIconPicker = () => {
        const picker = doc.querySelector('[data-app-icon-picker]');
        if (!picker) return;
        const options = Array.from(picker.querySelectorAll('[data-icon]'));
        let selected = window.localStorage?.getItem('hebrew-app-icon') || options[0]?.dataset.icon || 'sparkles';
        const trigger = doc.querySelector('[data-dialog-open="app-icon"]');
        const applyBtn = doc.querySelector('[data-save-app-icon]');

        const updateTriggerText = () => {
            if (trigger) {
                trigger.textContent = `Icon: ${selected}`;
            }
        };

        const updateSelection = () => {
            options.forEach((btn) => {
                btn.classList.toggle('selected', btn.dataset.icon === selected);
            });
        };

        options.forEach((btn) => {
            btn.addEventListener('click', () => {
                selected = btn.dataset.icon || selected;
                updateSelection();
            });
        });

        applyBtn?.addEventListener('click', () => {
            window.localStorage?.setItem('hebrew-app-icon', selected);
            updateTriggerText();
            showToast('App icon preference saved.', 'success');
            const dialog = doc.getElementById('dialog-app-icon');
            dialogController.close(dialog);
        });

        updateSelection();
        updateTriggerText();
    };

    const initDeckSheet = () => {
        const deckSheet = doc.getElementById('deck-sheet');
        if (!deckSheet) return;
        const title = doc.getElementById('deck-sheet-title');
        const closeButton = doc.querySelector('[data-deck-sheet-close]');
        const forms = deckSheet.querySelectorAll('form');
        let lastTrigger = null;

        const closeSheet = () => {
            deckSheet.setAttribute('hidden', 'hidden');
            deckSheet.removeEventListener('keydown', handleKeydown);
            if (lastTrigger) {
                lastTrigger.focus();
            }
        };

        const focusable = () => Array.from(deckSheet.querySelectorAll(focusableSelector));
        const handleKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeSheet();
                return;
            }
            if (event.key === 'Tab') {
                const nodes = focusable();
                if (!nodes.length) {
                    event.preventDefault();
                    return;
                }
                const first = nodes[0];
                const last = nodes[nodes.length - 1];
                if (event.shiftKey && doc.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && doc.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };

        doc.querySelectorAll('.deck-card-menu').forEach((button) => {
            button.addEventListener('click', () => {
                const deckId = button.dataset.deckSheet || '';
                const deckName = button.closest('.deck-card')?.querySelector('h3')?.textContent || 'Deck';
                const deckCard = button.closest('.deck-card');
                const isFrozen = deckCard?.dataset.frozen === '1';
                const isReversed = deckCard?.dataset.reversed === '1';
                deckSheet.removeAttribute('hidden');
                deckSheet.addEventListener('keydown', handleKeydown);
                const focusTarget = deckSheet.querySelector('.deck-sheet-content');
                requestAnimationFrame(() => focusTarget?.focus());
                lastTrigger = button;
                if (title) {
                    title.textContent = deckName;
                }
                forms.forEach((form) => {
                    const input = form.querySelector('input[name="deck_id"]');
                    if (input) {
                        input.value = deckId;
                    }
                    const toggle = form.dataset.sheetToggle || '';
                    const valueInput = form.querySelector('input[name="value"]');
                    const actionButton = form.querySelector('.sheet-action');
                    if (toggle === 'is_frozen' && valueInput && actionButton) {
                        valueInput.value = isFrozen ? '0' : '1';
                        actionButton.textContent = isFrozen ? 'Unfreeze' : 'Freeze';
                    }
                    if (toggle === 'is_reversed' && valueInput && actionButton) {
                        valueInput.value = isReversed ? '0' : '1';
                        actionButton.textContent = isReversed ? 'Normal order' : 'Reverse';
                    }
                });
            });
        });

        closeButton?.addEventListener('click', () => closeSheet());
        deckSheet.addEventListener('click', (event) => {
            if (event.target === deckSheet) {
                closeSheet();
            }
        });
    };

    const initDeckFilters = () => {
        const deckGrid = doc.getElementById('deck-grid');
        if (!deckGrid) return;
        doc.querySelectorAll('.filter-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const filter = button.dataset.filter;
                doc.querySelectorAll('.filter-btn').forEach((btn) => btn.classList.remove('active'));
                button.classList.add('active');
                deckGrid.querySelectorAll('.deck-card').forEach((card) => {
                    const category = card.dataset.category || 'General';
                    const popular = card.dataset.popular === '1';
                    let visible = filter === 'all';
                    if (filter === 'popular') {
                        visible = popular;
                    } else if (filter && filter !== 'all' && filter !== 'popular') {
                        visible = category === filter;
                    }
                    card.toggleAttribute('hidden', !visible);
                });
            });
        });
    };

    const initMemoryGame = () => {
        const dataElement = doc.getElementById('memory-data');
        const board = doc.getElementById('memory-board');
        const matchesEl = doc.getElementById('memory-matches');
        const feedbackEl = doc.getElementById('memory-feedback');
        const resetBtn = doc.getElementById('memory-reset');
        if (!dataElement || !board || !matchesEl) {
            return;
        }
        const basePairs = JSON.parse(dataElement.textContent || '[]');
        const matchedPairs = new Set();
        let flipped = [];

        const pickLabel = (item) => item.meaning || item.other_script || item.transliteration || '—';

        const buildDeck = () => {
            const deck = [];
            basePairs.forEach((item) => {
                deck.push({ pairId: String(item.id), type: 'hebrew', label: item.hebrew, announce: `Hebrew: ${item.hebrew}` });
                const translationLabel = pickLabel(item);
                deck.push({ pairId: String(item.id), type: 'translation', label: translationLabel, announce: `Translation: ${translationLabel}` });
            });
            for (let i = deck.length - 1; i > 0; i -= 1) {
                const j = Math.floor(Math.random() * (i + 1));
                [deck[i], deck[j]] = [deck[j], deck[i]];
            }
            return deck;
        };

        const clearBoardState = () => {
            matchedPairs.clear();
            flipped = [];
            matchesEl.textContent = '0';
            if (feedbackEl) {
                feedbackEl.textContent = '';
            }
        };

        const announceMatch = () => {
            if (!feedbackEl) return;
            if (matchedPairs.size === basePairs.length) {
                feedbackEl.textContent = 'All pairs matched! Great job!';
            } else {
                feedbackEl.textContent = `Matched ${matchedPairs.size} of ${basePairs.length} pairs.`;
            }
        };

        const unflipCards = (first, second) => {
            window.setTimeout(() => {
                first.classList.remove('flipped');
                second.classList.remove('flipped');
                first.disabled = false;
                second.disabled = false;
                flipped = [];
            }, 900);
        };

        const handleFlip = (button) => {
            if (button.classList.contains('matched') || flipped.includes(button)) {
                return;
            }
            button.classList.add('flipped');
            button.disabled = true;
            flipped.push(button);
            if (flipped.length === 2) {
                const [first, second] = flipped;
                const isMatch = first.dataset.pairId === second.dataset.pairId && first.dataset.type !== second.dataset.type;
                if (isMatch) {
                    first.classList.add('matched');
                    second.classList.add('matched');
                    matchedPairs.add(first.dataset.pairId || '');
                    matchesEl.textContent = String(matchedPairs.size);
                    flipped = [];
                    announceMatch();
                } else {
                    unflipCards(first, second);
                }
            }
        };

        const renderBoard = () => {
            const deck = buildDeck();
            clearBoardState();
            board.innerHTML = '';
            deck.forEach((card) => {
                const button = doc.createElement('button');
                button.type = 'button';
                button.className = 'memory-card';
                button.dataset.pairId = card.pairId;
                button.dataset.type = card.type;
                button.textContent = card.label;
                button.setAttribute('aria-label', card.announce);
                button.addEventListener('click', () => handleFlip(button));
                board.appendChild(button);
            });
        };

        resetBtn?.addEventListener('click', () => {
            renderBoard();
        });

        renderBoard();
    };

    const initRecording = () => {
        const recordToggle = doc.getElementById('record-toggle');
        const recordSave = doc.getElementById('record-save');
        const recordDiscard = doc.getElementById('record-discard');
        const recordPreview = doc.getElementById('record-preview');
        const recordedAudioElement = doc.getElementById('recorded-audio');
        const recordedAudioInput = doc.getElementById('recorded_audio');
        const fileInput = doc.getElementById('audio');
        if (!recordToggle || !recordedAudioElement || !recordedAudioInput) {
            return;
        }

        let mediaRecorder = null;
        let audioChunks = [];
        let mediaStream = null;
        let recordedBlob = null;

        const stopStream = () => {
            if (mediaStream) {
                mediaStream.getTracks().forEach((track) => track.stop());
                mediaStream = null;
            }
        };

        const resetRecording = () => {
            audioChunks = [];
            recordedBlob = null;
            recordedAudioInput.value = '';
            if (recordSave) recordSave.disabled = true;
            if (recordPreview) recordPreview.hidden = true;
            recordedAudioElement.src = '';
            stopStream();
            recordToggle.dataset.state = 'idle';
            recordToggle.textContent = '🎙️ Record';
        };

        const enableRecordingUI = (enabled) => {
            if (fileInput) fileInput.disabled = !enabled;
            recordToggle.disabled = !enabled;
        };

        const startRecording = async () => {
            if (!navigator.mediaDevices?.getUserMedia) {
                showToast('Recording is not supported on this device.', 'error');
                return;
            }
            try {
                mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(mediaStream);
                audioChunks = [];
                mediaRecorder.addEventListener('dataavailable', (event) => {
                    if (event.data.size > 0) {
                        audioChunks.push(event.data);
                    }
                });
                mediaRecorder.addEventListener('stop', () => {
                    recordedBlob = new Blob(audioChunks, { type: mediaRecorder?.mimeType || 'audio/webm' });
                    const reader = new FileReader();
                    reader.onloadend = () => {
                        recordedAudioInput.value = String(reader.result || '');
                        recordedAudioElement.src = reader.result ? String(reader.result) : '';
                        if (recordPreview) recordPreview.hidden = false;
                        if (recordSave) recordSave.disabled = false;
                    };
                    reader.readAsDataURL(recordedBlob);
                    enableRecordingUI(true);
                    stopStream();
                });
                mediaRecorder.start();
                recordToggle.dataset.state = 'recording';
                recordToggle.textContent = '⏹️ Stop';
                enableRecordingUI(false);
            } catch (error) {
                console.error('Unable to start recording', error);
                showToast('Unable to access microphone.', 'error');
                enableRecordingUI(true);
            }
        };

        const stopRecording = () => {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            recordToggle.dataset.state = 'processing';
            recordToggle.textContent = 'Processing…';
        };

        recordToggle.addEventListener('click', () => {
            const state = recordToggle.dataset.state || 'idle';
            if (state === 'idle') {
                startRecording();
            } else if (state === 'recording') {
                stopRecording();
            }
        });

        recordSave?.addEventListener('click', () => {
            if (recordedBlob && recordedAudioInput.value) {
                recordToggle.dataset.state = 'saved';
                recordToggle.textContent = 'Recorded';
                recordSave.disabled = true;
            }
        });

        recordDiscard?.addEventListener('click', () => {
            resetRecording();
        });
    };

    const initSettingsToggles = () => {
        const remindersToggle = doc.querySelector('[data-toggle="reminders"]');
        const hapticsToggle = doc.querySelector('[data-toggle="haptics"]');
        const remindersMessage = doc.querySelector('[data-reminders-message]');
        const hapticsMessage = doc.querySelector('[data-haptics-message]');
        const storage = window.localStorage;

        const updateSwitch = (button, value) => {
            if (!button) return;
            button.classList.toggle('on', value);
            button.setAttribute('aria-pressed', value ? 'true' : 'false');
            const sr = button.querySelector('.sr-only');
            if (sr) {
                sr.textContent = value ? 'On' : 'Off';
            }
        };

        if (remindersToggle) {
            if (!('Notification' in window)) {
                remindersToggle.disabled = true;
                if (remindersMessage) {
                    remindersMessage.textContent = 'Notifications are not supported in this browser.';
                    remindersMessage.hidden = false;
                }
            }
            const stored = storage?.getItem('hebrew-reminders') === 'on';
            updateSwitch(remindersToggle, stored);
            remindersToggle.addEventListener('click', async () => {
                if (remindersToggle.disabled) return;
                const next = !remindersToggle.classList.contains('on');
                if (next) {
                    try {
                        const permission = await Notification.requestPermission();
                        if (permission !== 'granted') {
                            updateSwitch(remindersToggle, false);
                            storage?.setItem('hebrew-reminders', 'off');
                            if (remindersMessage) {
                                remindersMessage.textContent = 'Permission is required to enable reminders.';
                                remindersMessage.hidden = false;
                            }
                            showToast('Notifications permission denied.', 'error');
                            return;
                        }
                    } catch (error) {
                        updateSwitch(remindersToggle, false);
                        showToast('Unable to request notification permission.', 'error');
                        return;
                    }
                }
                updateSwitch(remindersToggle, next);
                storage?.setItem('hebrew-reminders', next ? 'on' : 'off');
                if (remindersMessage) remindersMessage.hidden = true;
                showToast(next ? 'Study reminders enabled.' : 'Study reminders disabled.', 'success');
            });
        }

        if (hapticsToggle) {
            const supported = 'vibrate' in navigator;
            if (!supported) {
                hapticsToggle.disabled = true;
                if (hapticsMessage) {
                    hapticsMessage.textContent = 'Haptic feedback is not available on this device.';
                    hapticsMessage.hidden = false;
                }
            }
            const stored = storage?.getItem('hebrew-haptics') === 'on';
            updateSwitch(hapticsToggle, supported && stored);
            hapticsToggle.addEventListener('click', () => {
                if (hapticsToggle.disabled) return;
                const next = !hapticsToggle.classList.contains('on');
                updateSwitch(hapticsToggle, next);
                storage?.setItem('hebrew-haptics', next ? 'on' : 'off');
                if (next && navigator.vibrate) {
                    navigator.vibrate(20);
                }
            });
        }
    };

    const populateVoiceSelect = (langSelect, voiceSelect) => {
        if (!langSelect || !voiceSelect) return;
        const lang = langSelect.value;
        const initialVoiceAttr = voiceSelect.getAttribute('data-initial-voice');
        const currentValue = voiceSelect.value;
        const preferredVoice = initialVoiceAttr !== null ? initialVoiceAttr : currentValue;
        const options = voicesCache.filter((voice) => voice.lang === lang);
        voiceSelect.innerHTML = '<option value="">System default</option>';
        if (!options.length) {
            voiceSelect.disabled = true;
            voiceSelect.value = '';
            return;
        }
        const fragment = doc.createDocumentFragment();
        options.forEach((voice) => {
            const option = doc.createElement('option');
            option.value = voice.name;
            option.textContent = `${voice.name} (${voice.lang})`;
            fragment.appendChild(option);
        });
        voiceSelect.appendChild(fragment);
        voiceSelect.disabled = false;
        if (preferredVoice) {
            voiceSelect.value = preferredVoice;
        }
        if (initialVoiceAttr !== null) {
            voiceSelect.removeAttribute('data-initial-voice');
        }
    };

    const initTtsForm = () => {
        const form = doc.querySelector('[data-tts-form]');
        if (!form) return;
        const saveButton = form.querySelector('[data-tts-submit]');
        const frontLang = form.querySelector('select[name="front_lang"]');
        const backLang = form.querySelector('select[name="back_lang"]');
        const frontVoice = form.querySelector('select[name="front_voice"]');
        const backVoice = form.querySelector('select[name="back_voice"]');
        const supportMessage = doc.querySelector('[data-tts-support]');
        const previewButton = doc.querySelector('[data-tts-play]');
        const previewMessage = doc.querySelector('[data-tts-message]');
        const sample = doc.querySelector('[data-tts-sample]');

        const initialData = (() => {
            const formData = new FormData(form);
            return {
                front_lang: formData.get('front_lang') || '',
                back_lang: formData.get('back_lang') || '',
                front_voice: formData.get('front_voice') || '',
                back_voice: formData.get('back_voice') || ''
            };
        })();

        const updateDirtyState = () => {
            const formData = new FormData(form);
            const dirty = ['front_lang', 'back_lang', 'front_voice', 'back_voice'].some((name) => {
                return (formData.get(name) || '') !== (initialData[name] || '');
            });
            if (saveButton) {
                saveButton.disabled = !dirty;
            }
        };

        const updateSupportNotice = () => {
            if (!supportMessage) {
                return;
            }
            if (!voicesCache.length) {
                supportMessage.textContent = 'No speech synthesis voices were found on this device.';
                supportMessage.hidden = false;
                return;
            }
            const lacksFront = !!frontVoice && !frontVoice.disabled && frontVoice.options.length <= 1;
            const lacksBack = !!backVoice && !backVoice.disabled && backVoice.options.length <= 1;
            if (frontVoice?.disabled || backVoice?.disabled) {
                supportMessage.textContent = 'No dedicated voice was found for one of the selected languages.';
                supportMessage.hidden = false;
            } else if (lacksFront || lacksBack) {
                supportMessage.textContent = 'No voice options available for the chosen language.';
                supportMessage.hidden = false;
            } else {
                supportMessage.hidden = true;
            }
        };

        if (!('speechSynthesis' in window)) {
            if (supportMessage) {
                supportMessage.textContent = 'Text-to-speech is not supported in this browser.';
                supportMessage.hidden = false;
            }
            saveButton?.setAttribute('disabled', 'disabled');
            previewButton?.setAttribute('disabled', 'disabled');
            frontVoice?.setAttribute('disabled', 'disabled');
            backVoice?.setAttribute('disabled', 'disabled');
            return;
        }

        const refreshVoices = () => {
            voicesCache = window.speechSynthesis.getVoices();
            populateVoiceSelect(frontLang, frontVoice);
            populateVoiceSelect(backLang, backVoice);
            updateDirtyState();
            updateSupportNotice();
        };

        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = refreshVoices;
        }
        refreshVoices();

        form.addEventListener('change', (event) => {
            const target = event.target;
            if (target === frontLang) {
                populateVoiceSelect(frontLang, frontVoice);
            }
            if (target === backLang) {
                populateVoiceSelect(backLang, backVoice);
            }
            updateDirtyState();
            updateSupportNotice();
        });

        form.addEventListener('input', updateDirtyState);

        previewButton?.addEventListener('click', () => {
            if (!sample) {
                return;
            }
            if (!('speechSynthesis' in window)) {
                if (previewMessage) {
                    previewMessage.textContent = 'Speech synthesis is unavailable.';
                    previewMessage.hidden = false;
                }
                return;
            }
            const text = Array.from(sample.querySelectorAll('h4, p')).map((node) => node.textContent || '').join('. ');
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = backLang?.value || 'he-IL';
            const selectedVoiceName = backVoice?.value || '';
            const selectedVoice = voicesCache.find((voice) => voice.name === selectedVoiceName);
            if (selectedVoice) {
                utterance.voice = selectedVoice;
            }
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utterance);
            if (previewMessage) {
                previewMessage.textContent = selectedVoice ? 'Playing selected voice.' : 'Playing default browser voice.';
                previewMessage.hidden = false;
                window.setTimeout(() => {
                    previewMessage.hidden = true;
                }, 2500);
            }
        });
    };

    const initSettingsScreen = () => {
        initSettingsToggles();
        initTtsForm();
        initLanguagePicker();
        initAppIconPicker();
    };

    const initLibraryScreen = () => {
        initDeckSheet();
        initDeckFilters();
    };

    const initHomeScreen = () => {
        initMemoryGame();
        initRecording();
    };

    const init = () => {
        applyLanguageDirection();
        initFlashToast();
        syncSearchScreen();
        initNavigation();
        initDialogs();
        initConfirmationHandlers();

        if (screen === 'home') {
            initHomeScreen();
        }
        if (screen === 'library') {
            initLibraryScreen();
        }
        if (screen === 'settings') {
            initSettingsScreen();
        }
    };

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
