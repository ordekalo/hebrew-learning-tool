const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const html = document.documentElement;
    const toastEl = document.getElementById('global-toast');
    let toastTimer;

    const clearToastClasses = () => {
        toastEl?.classList.remove('toast-show', 'toast-hide', 'toast-success', 'toast-error', 'toast-info');
    };

    const showToast = (message, tone = 'success') => {
        if (!toastEl) return;
        window.clearTimeout(toastTimer);
        toastEl.textContent = message;
        toastEl.hidden = false;
        clearToastClasses();
        toastEl.classList.add(`toast-${tone}`);
        toastEl.classList.add('toast-show');
        toastTimer = window.setTimeout(() => {
            toastEl.classList.add('toast-hide');
        }, 2800);
    };

    toastEl?.addEventListener('transitionend', () => {
        if (!toastEl.classList.contains('toast-hide')) {
            return;
        }
        toastEl.hidden = true;
        clearToastClasses();
    });

    const locale = (body.dataset.locale || 'en-US').replace('_', '-');
    html.lang = locale;
    const rtlLocales = ['he', 'ar', 'fa', 'ur'];
    const isRTL = rtlLocales.some((code) => locale.toLowerCase().startsWith(code));
    html.dir = isRTL ? 'rtl' : 'ltr';
    body.classList.toggle('rtl', isRTL);
    document.getElementById('hebrew')?.setAttribute('dir', 'rtl');
    const otherScriptInput = document.getElementById('other_script');
    if (otherScriptInput) {
        otherScriptInput.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
    }

    const navItems = Array.from(document.querySelectorAll('.bottom-nav-item'));
    const screens = Array.from(document.querySelectorAll('.screen'));
    const applyScreen = (target) => {
        screens.forEach((section) => {
            section.toggleAttribute('hidden', (section.dataset.screen || '') !== target);
        });
        body.dataset.screen = target;
        navItems.forEach((item) => {
            const isActive = (item.dataset.nav || '') === target;
            item.classList.toggle('active', isActive);
            item.setAttribute('aria-current', isActive ? 'page' : 'false');
        });
    };
    const savedScreen = sessionStorage.getItem('hebrew-active-screen');
    const initialScreen = savedScreen && screens.some((section) => section.dataset.screen === savedScreen)
        ? savedScreen
        : (body.dataset.screen || 'home');
    applyScreen(initialScreen);
    navItems.forEach((item) => {
        item.addEventListener('click', () => {
            const nav = item.dataset.nav || 'home';
            sessionStorage.setItem('hebrew-active-screen', nav);
        });
    });

    const langSelect = document.getElementById('lang-filter');
    if (langSelect) {
        const storage = window.localStorage;
        const currentLang = body.dataset.langFilter || '';
        const storedLang = storage.getItem('hebrew-lang-filter');
        if (currentLang) {
            storage.setItem('hebrew-lang-filter', currentLang);
        } else if (storedLang) {
            langSelect.value = storedLang;
        }
        langSelect.addEventListener('change', () => {
            storage.setItem('hebrew-lang-filter', langSelect.value);
            const url = new URL(window.location.href);
            if (langSelect.value) {
                url.searchParams.set('lang', langSelect.value);
            } else {
                url.searchParams.delete('lang');
            }
            url.searchParams.set('screen', 'home');
            window.location.href = url.toString();
        });
    }

    const memoryDataEl = document.getElementById('memory-data');
    const board = document.getElementById('memory-board');
    const matchesEl = document.getElementById('memory-matches');
    const feedbackEl = document.getElementById('memory-feedback');
    const resetBtn = document.getElementById('memory-reset');

    if (memoryDataEl && board && matchesEl) {
        const basePairs = JSON.parse(memoryDataEl.textContent || '[]');
        const matchedPairs = new Set();
        let flipped = [];

        const pickLabel = (item) => item.meaning || item.other_script || item.transliteration || '—';

        const buildDeck = () => {
            const deck = [];
            basePairs.forEach((item) => {
                deck.push({ pairId: String(item.id), type: 'hebrew', label: item.hebrew, announce: `Hebrew: ${item.hebrew}` });
                deck.push({ pairId: String(item.id), type: 'translation', label: pickLabel(item), announce: `Translation: ${pickLabel(item)}` });
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
            if (matchedPairs.size === basePairs.length && basePairs.length > 0) {
                feedbackEl.textContent = 'All pairs matched! Great job!';
            } else if (basePairs.length > 0) {
                feedbackEl.textContent = `Matched ${matchedPairs.size} of ${basePairs.length} pairs.`;
            } else {
                feedbackEl.textContent = '';
            }
        };

        const renderBoard = () => {
            board.innerHTML = '';
            clearBoardState();
            if (basePairs.length === 0) {
                announceMatch();
                return;
            }
            const fragment = document.createDocumentFragment();
            const deck = buildDeck();
            deck.forEach((card, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'memory-card';
                button.dataset.pairId = card.pairId;
                button.dataset.type = card.type;
                button.setAttribute('aria-label', card.announce);
                button.dataset.index = String(index);
                const span = document.createElement('span');
                span.textContent = card.label;
                button.appendChild(span);
                fragment.appendChild(button);
            });
            board.appendChild(fragment);
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

        board.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const button = target.closest('button.memory-card');
            if (button) {
                handleFlip(button);
            }
        });

        renderBoard();
        if (resetBtn) {
            resetBtn.disabled = basePairs.length === 0;
            resetBtn.addEventListener('click', () => {
                renderBoard();
            });
        }
    }

    const recordToggle = document.getElementById('record-toggle');
    const recordSave = document.getElementById('record-save');
    const recordDiscard = document.getElementById('record-discard');
    const recordPreview = document.getElementById('record-preview');
    const recordedAudioElement = document.getElementById('recorded-audio');
    const recordedAudioInput = document.getElementById('recorded_audio');
    const fileInput = document.getElementById('audio');
    const recordSupportMessage = document.getElementById('record-support-message');
    const MAX_AUDIO_BYTES = 10 * 1024 * 1024;

    const supportsRecording = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    if (!supportsRecording) {
        recordToggle?.setAttribute('hidden', 'hidden');
        recordSave?.setAttribute('hidden', 'hidden');
        recordSupportMessage?.removeAttribute('hidden');
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
        if (recordedAudioInput) {
            recordedAudioInput.value = '';
        }
        if (recordedAudioElement) {
            recordedAudioElement.src = '';
        }
        if (recordPreview) {
            recordPreview.hidden = true;
        }
        if (recordSave) {
            recordSave.disabled = true;
        }
        recordDiscard?.setAttribute('hidden', 'hidden');
        if (recordToggle) {
            recordToggle.dataset.state = 'idle';
            recordToggle.textContent = '🎙️ Record';
            recordToggle.disabled = !supportsRecording;
        }
        if (fileInput) {
            fileInput.disabled = false;
        }
        stopStream();
    };

    const startRecording = async () => {
        if (!supportsRecording || !recordToggle) return;
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
                if (recordedBlob.size > MAX_AUDIO_BYTES) {
                    showToast('The recording exceeds 10MB. Please record a shorter clip.', 'error');
                    resetRecording();
                    return;
                }
                const reader = new FileReader();
                reader.onloadend = () => {
                    const result = reader.result ? String(reader.result) : '';
                    if (recordedAudioInput) {
                        recordedAudioInput.value = result;
                    }
                    if (recordedAudioElement) {
                        recordedAudioElement.src = result;
                    }
                    recordPreview?.removeAttribute('hidden');
                    recordSave?.removeAttribute('disabled');
                    recordDiscard?.removeAttribute('hidden');
                    if (recordToggle) {
                        recordToggle.dataset.state = 'saved';
                        recordToggle.textContent = 'Recorded';
                    }
                    if (fileInput) {
                        fileInput.disabled = false;
                    }
                };
                reader.readAsDataURL(recordedBlob);
                stopStream();
            });

            if (fileInput) {
                fileInput.disabled = true;
            }
            mediaRecorder.start();
            recordToggle.dataset.state = 'recording';
            recordToggle.textContent = '⏹️ Stop';
        } catch (error) {
            console.error('Unable to start recording', error);
            showToast('Unable to access microphone.', 'error');
            resetRecording();
        }
    };

    const stopRecording = () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        if (recordToggle) {
            recordToggle.dataset.state = 'processing';
            recordToggle.textContent = 'Processing…';
        }
    };

    if (supportsRecording && recordToggle) {
        recordToggle.addEventListener('click', () => {
            const state = recordToggle.dataset.state || 'idle';
            if (state === 'idle' || state === 'saved') {
                startRecording();
            } else if (state === 'recording') {
                stopRecording();
            }
        });
    }

    recordSave?.addEventListener('click', () => {
        if (!recordedBlob || !recordedAudioInput?.value) {
            showToast('אין הקלטה לשמירה.', 'error');
            return;
        }
        showToast('ההקלטה תשמר עם שליחת הטופס.', 'info');
        recordSave.disabled = true;
    });

    recordDiscard?.addEventListener('click', () => {
        resetRecording();
    });

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file && file.size > MAX_AUDIO_BYTES) {
            showToast('Audio file is larger than 10MB. Please pick a smaller file.', 'error');
            fileInput.value = '';
        }
    });

    const quickAddForm = document.getElementById('quick-add-form');
    const starterDataEl = document.getElementById('starter-phrases');
    const starterData = starterDataEl ? JSON.parse(starterDataEl.textContent || '[]') : [];
    let starterIndex = 0;
    const rollExampleTrigger = document.querySelector('[data-roll-example]');

    const setFieldValue = (form, selector, value) => {
        const field = form.querySelector(selector);
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
            field.value = value;
        }
    };

    if (rollExampleTrigger && quickAddForm && starterData.length) {
        rollExampleTrigger.addEventListener('click', () => {
            const phrase = starterData[starterIndex % starterData.length];
            starterIndex += 1;
            setFieldValue(quickAddForm, '[name="hebrew"]', phrase.hebrew || '');
            setFieldValue(quickAddForm, '[name="transliteration"]', phrase.transliteration || '');
            setFieldValue(quickAddForm, '[name="lang_code"]', phrase.lang || '');
            setFieldValue(quickAddForm, '[name="meaning"]', phrase.meaning || '');
            setFieldValue(quickAddForm, '[name="example"]', phrase.example || '');
            setFieldValue(quickAddForm, '[name="notes"]', '');
            setFieldValue(quickAddForm, '[name="other_script"]', '');
            setFieldValue(quickAddForm, '[name="part_of_speech"]', 'phrase');
            showToast('הוזנה דוגמה מוכנה מראש.', 'info');
        });
    }

    if (quickAddForm) {
        const submitBtn = quickAddForm.querySelector('button[type="submit"]');
        quickAddForm.addEventListener('submit', async (event) => {
            if (quickAddForm.dataset.disableAjax === 'true') {
                return;
            }
            event.preventDefault();
            if (!submitBtn) {
                quickAddForm.submit();
                return;
            }
            submitBtn.disabled = true;
            const formData = new FormData(quickAddForm);
            const uploadFile = fileInput?.files?.[0];
            if (uploadFile && uploadFile.size > MAX_AUDIO_BYTES) {
                showToast('Audio file is larger than 10MB. Please pick a smaller file.', 'error');
                submitBtn.disabled = false;
                return;
            }
            try {
                const response = await fetch(quickAddForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: formData,
                });
                if (!response.ok) {
                    throw new Error('Unable to add card.');
                }
                const data = await response.json();
                if (!data?.success) {
                    throw new Error(data?.message || 'Unable to add card.');
                }
                ['hebrew', 'transliteration', 'lang_code', 'meaning', 'notes', 'example', 'other_script'].forEach((name) => {
                    setFieldValue(quickAddForm, `[name="${name}"]`, '');
                });
                const posField = quickAddForm.querySelector('[name="part_of_speech"]');
                if (posField instanceof HTMLSelectElement) {
                    posField.value = '';
                }
                if (fileInput) {
                    fileInput.value = '';
                }
                const hebrewField = quickAddForm.querySelector('[name="hebrew"]');
                hebrewField?.focus();
                showToast(data.message || 'נוסף בהצלחה');
            } catch (error) {
                console.error(error);
                showToast(error instanceof Error ? error.message : 'Unable to add card.', 'error');
                quickAddForm.dataset.disableAjax = 'true';
                quickAddForm.submit();
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

    document.querySelectorAll('.seed-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(form),
                });
                if (!response.ok) {
                    throw new Error('Unable to seed starter phrases.');
                }
                const data = await response.json();
                const added = Number(data?.added ?? 0);
                showToast(added > 0 ? `${added} starter phrases added.` : 'Starter phrases are already in this deck.');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                console.error(error);
                showToast('Unable to seed starter phrases.', 'error');
                if (button) {
                    button.disabled = false;
                }
                form.submit();
            }
        });
    });

    const remindersToggle = document.querySelector('[data-toggle="reminders"]');
    const hapticsToggle = document.querySelector('[data-toggle="haptics"]');
    const storage = window.localStorage;
    const updateSwitchState = (el, value, label) => {
        if (!el) return;
        el.classList.toggle('on', value);
        el.setAttribute('aria-pressed', value ? 'true' : 'false');
        const sr = el.querySelector('.sr-only');
        if (sr) {
            sr.textContent = `${label} ${value ? 'on' : 'off'}`;
        }
    };

    const remindersState = storage.getItem('hebrew-reminders') === 'on';
    const hapticsState = storage.getItem('hebrew-haptics') === 'on';
    updateSwitchState(remindersToggle, remindersState, 'Reminders');
    updateSwitchState(hapticsToggle, hapticsState, 'Haptics');

    remindersToggle?.addEventListener('click', () => {
        const next = !remindersToggle.classList.contains('on');
        updateSwitchState(remindersToggle, next, 'Reminders');
        storage.setItem('hebrew-reminders', next ? 'on' : 'off');
    });

    hapticsToggle?.addEventListener('click', () => {
        const next = !hapticsToggle.classList.contains('on');
        updateSwitchState(hapticsToggle, next, 'Haptics');
        storage.setItem('hebrew-haptics', next ? 'on' : 'off');
        if (next && 'vibrate' in navigator) {
            navigator.vibrate?.(20);
        }
    });

    const deckSheet = document.getElementById('deck-sheet');
    const deckSheetContent = deckSheet?.querySelector('.deck-sheet-content');
    const deckSheetTitle = document.getElementById('deck-sheet-title');
    const deckSheetClose = document.querySelector('[data-deck-sheet-close]');
    const deckSheetForms = deckSheet?.querySelectorAll('form');

    const createFocusTrap = (container, onEscape) => {
        const getFocusable = () => Array.from(container.querySelectorAll(focusableSelector)).filter((el) => !el.hasAttribute('hidden') && !el.closest('[hidden]'));
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onEscape();
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = getFocusable();
            if (focusable.length === 0) {
                event.preventDefault();
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        container.addEventListener('keydown', handleKeyDown);
        const focusable = getFocusable();
        const target = focusable[0] || container;
        if (target instanceof HTMLElement) {
            target.focus();
        }
        return () => {
            container.removeEventListener('keydown', handleKeyDown);
        };
    };

    let releaseDeckSheetTrap = null;
    let deckSheetTrigger = null;

    const closeDeckSheet = () => {
        if (!deckSheet || !deckSheetContent) return;
        deckSheet.setAttribute('hidden', 'hidden');
        releaseDeckSheetTrap?.();
        releaseDeckSheetTrap = null;
        deckSheetTrigger?.focus();
        deckSheetTrigger = null;
    };

    document.querySelectorAll('.deck-card-menu').forEach((button) => {
        button.addEventListener('click', () => {
            if (!deckSheet || !deckSheetContent) return;
            const deckId = button.dataset.deckSheet || '';
            const deckCard = button.closest('.deck-card');
            const deckName = deckCard?.querySelector('h3')?.textContent || 'Deck';
            const isFrozen = deckCard?.dataset.frozen === '1';
            const isReversed = deckCard?.dataset.reversed === '1';
            deckSheet.removeAttribute('hidden');
            deckSheetTitle.textContent = deckName;
            deckSheetForms?.forEach((form) => {
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
            deckSheetTrigger = button;
            releaseDeckSheetTrap = createFocusTrap(deckSheetContent, closeDeckSheet);
        });
    });

    deckSheetClose?.addEventListener('click', () => {
        closeDeckSheet();
    });

    deckSheet?.addEventListener('click', (event) => {
        if (event.target === deckSheet) {
            closeDeckSheet();
        }
    });

    const dialogTriggers = document.querySelectorAll('[data-dialog-open]');
    const dialogCloseButtons = document.querySelectorAll('[data-dialog-close]');
    const dialogTraps = new Map();

    const closeDialog = (dialog) => {
        const meta = dialogTraps.get(dialog);
        dialog.setAttribute('hidden', 'hidden');
        meta?.release?.();
        meta?.trigger?.focus();
        dialogTraps.delete(dialog);
    };

    dialogTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const id = trigger.dataset.dialogOpen;
            const dialog = id ? document.getElementById(`dialog-${id}`) : null;
            if (!dialog) return;
            dialog.removeAttribute('hidden');
            const content = dialog.querySelector('.dialog-content');
            const release = content instanceof HTMLElement ? createFocusTrap(content, () => closeDialog(dialog)) : null;
            dialogTraps.set(dialog, { release, trigger });
        });
    });

    dialogCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('.dialog');
            if (dialog) {
                closeDialog(dialog);
            }
        });
    });

    document.querySelectorAll('.dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closeDialog(dialog);
            }
        });
    });

    const deckGrid = document.getElementById('deck-grid');
    document.querySelectorAll('.filter-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter;
            document.querySelectorAll('.filter-btn').forEach((btn) => btn.classList.remove('active'));
            button.classList.add('active');
            if (!deckGrid) return;
            deckGrid.querySelectorAll('.deck-card').forEach((card) => {
                const category = card.dataset.category || 'General';
                const popular = card.dataset.popular === '1';
                let visible = filter === 'all';
                if (filter === 'popular') {
                    visible = popular;
                } else if (filter !== 'all' && filter !== 'popular') {
                    visible = category === filter;
                }
                card.toggleAttribute('hidden', !visible);
            });
        });
    });

    const ttsButton = document.querySelector('[data-tts-play]');
    const ttsSample = document.querySelector('[data-tts-sample]');
    const ttsBackLang = body.dataset.ttsBackLang || 'he-IL';

    if (!('speechSynthesis' in window) || !ttsButton || !ttsSample) {
        ttsButton?.setAttribute('hidden', 'hidden');
    } else {
        const pickVoice = () => {
            const voices = window.speechSynthesis.getVoices();
            return voices.find((voice) => voice.lang === ttsBackLang)
                || voices.find((voice) => voice.lang.startsWith(ttsBackLang.split('-')[0]));
        };

        ttsButton.addEventListener('click', () => {
            const text = Array.from(ttsSample.querySelectorAll('h4, p')).map((node) => node.textContent || '').join('. ');
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = ttsBackLang;
            const voice = pickVoice();
            if (voice) {
                utterance.voice = voice;
            }
            window.speechSynthesis.speak(utterance);
        });
    }
});
