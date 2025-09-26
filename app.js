const recordToggle = document.getElementById('record-toggle');
const recordSave = document.getElementById('record-save');
const recordDiscard = document.getElementById('record-discard');
const recordedAudio = document.getElementById('recorded-audio');
const recordedInput = document.getElementById('recorded_audio');

let mediaRecorder = null;
let chunks = [];

function resetRecording() {
    chunks = [];
    if (recordedAudio) {
        recordedAudio.src = '';
        recordedAudio.setAttribute('hidden', 'hidden');
    }
    if (recordedInput) {
        recordedInput.value = '';
    }
    recordSave?.setAttribute('disabled', 'disabled');
    recordDiscard?.setAttribute('hidden', 'hidden');
    if (recordToggle) {
        recordToggle.dataset.state = 'idle';
        recordToggle.textContent = '🎙️ הקלטה';
    }
}

async function startRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Recording is not supported on this device.');
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        chunks = [];
        mediaRecorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                chunks.push(event.data);
            }
        };
        mediaRecorder.onstop = () => {
            const blob = new Blob(chunks, { type: 'audio/webm' });
            const reader = new FileReader();
            reader.onloadend = () => {
                if (recordedInput) {
                    recordedInput.value = reader.result;
                }
            };
            reader.readAsDataURL(blob);
            if (recordedAudio) {
                recordedAudio.src = URL.createObjectURL(blob);
                recordedAudio.removeAttribute('hidden');
            }
            recordSave?.removeAttribute('disabled');
            recordDiscard?.removeAttribute('hidden');
        };
        mediaRecorder.start();
        if (recordToggle) {
            recordToggle.dataset.state = 'recording';
            recordToggle.textContent = '⏹️ עצור';
        }
    } catch (error) {
        console.error('Unable to start recording', error);
        alert('Unable to access microphone.');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
}

recordToggle?.addEventListener('click', () => {
    const state = recordToggle.dataset.state;
    if (state === 'recording') {
        stopRecording();
    } else {
        startRecording();
    }
});

recordSave?.addEventListener('click', () => {
    if (!recordedInput?.value) {
        alert('אין הקלטה לשמירה.');
        return;
    }
    alert('ההקלטה תשמר עם שליחת הטופס.');
});

recordDiscard?.addEventListener('click', () => {
    resetRecording();
});

if (recordedAudio) {
    recordedAudio.addEventListener('loadedmetadata', () => {
        if (recordedAudio.duration < 0.5) {
            resetRecording();
        }
    });
}

if (!navigator.mediaDevices) {
    recordToggle?.setAttribute('hidden', 'hidden');
    recordSave?.setAttribute('hidden', 'hidden');
}
