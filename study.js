const main = document.querySelector('.study-main');
const deck = main?.dataset.deck || '';
const csrf = main?.dataset.csrf || '';
const dueCountEl = document.getElementById('due-count');
const cardEl = document.getElementById('study-card');
const emptyEl = document.getElementById('study-empty');
const cardHebrew = document.getElementById('card-hebrew');
const cardTranslit = document.getElementById('card-transliteration');
const cardNotes = document.getElementById('card-notes');
const cardTranslations = document.getElementById('card-translations');
const cardTags = document.getElementById('card-tags');
const cardImage = document.getElementById('card-image');
const cardAudio = document.getElementById('card-audio');
const ttsButton = document.getElementById('tts-button');
const buttons = Array.from(document.querySelectorAll('.srs-btn'));

let currentCard = null;
let loading = false;
let touchStartX = 0;
let touchEndX = 0;

async function fetchNext() {
    if (loading) return;
    loading = true;
    try {
        const params = new URLSearchParams();
        if (deck) params.set('deck', deck);
        const response = await fetch(`api/learn_next.php?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const data = await response.json();
        if (!data.card) {
            showEmpty();
            updateDue(data.dueCount ?? 0);
            return;
        }
        currentCard = data.card;
        renderCard(data.card);
        updateDue(data.dueCount ?? 0);
    } catch (error) {
        console.error('Failed to load next card', error);
    } finally {
        loading = false;
    }
}

function updateDue(count) {
    if (dueCountEl) {
        dueCountEl.textContent = count.toString();
    }
}

function showEmpty() {
    cardEl?.setAttribute('hidden', 'hidden');
    emptyEl?.removeAttribute('hidden');
}

function renderCard(card) {
    emptyEl?.setAttribute('hidden', 'hidden');
    cardEl?.removeAttribute('hidden');

    cardHebrew.textContent = card.hebrew || '—';
    cardTranslit.textContent = card.transliteration || '';
    cardNotes.textContent = card.notes || '';

    cardTranslations.innerHTML = '';
    if (Array.isArray(card.translations) && card.translations.length > 0) {
        card.translations.forEach((item) => {
            const li = document.createElement('li');
            li.innerHTML = `<strong>${item.lang_code || ''}</strong> ${item.meaning || ''}`;
            cardTranslations.append(li);
        });
    } else {
        const li = document.createElement('li');
        li.textContent = '—';
        cardTranslations.append(li);
    }

    cardTags.innerHTML = '';
    if (Array.isArray(card.tags)) {
        card.tags.forEach((tag) => {
            const span = document.createElement('span');
            span.className = 'tag-chip';
            span.textContent = tag;
            cardTags.append(span);
        });
    }

    if (card.image_path) {
        cardImage.src = card.image_path;
        cardImage.removeAttribute('hidden');
    } else {
        cardImage.setAttribute('hidden', 'hidden');
    }

    if (card.audio_path) {
        cardAudio.src = card.audio_path;
        cardAudio.removeAttribute('hidden');
        ttsButton.setAttribute('hidden', 'hidden');
    } else {
        cardAudio.setAttribute('hidden', 'hidden');
        cardAudio.removeAttribute('src');
        if ('speechSynthesis' in window) {
            ttsButton.removeAttribute('hidden');
        } else {
            ttsButton.setAttribute('hidden', 'hidden');
        }
    }
}

async function submitResult(result) {
    if (!currentCard) {
        return;
    }
    try {
        const response = await fetch('api/learn_answer.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF': csrf,
            },
            body: JSON.stringify({ word_id: currentCard.id, result, deck }),
        });
        if (!response.ok) {
            throw new Error('Bad response');
        }
        const payload = await response.json();
        updateDue(payload.dueCount ?? 0);
        if (['again', 'hard'].includes(result) && 'vibrate' in navigator) {
            navigator.vibrate(40);
        }
        await fetchNext();
    } catch (error) {
        console.error('Failed to submit answer', error);
    }
}

buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
        const { result } = btn.dataset;
        if (result) {
            submitResult(result);
        }
    });
});

cardEl?.addEventListener('touchstart', (event) => {
    if (event.changedTouches && event.changedTouches.length > 0) {
        touchStartX = event.changedTouches[0].screenX;
    }
});

cardEl?.addEventListener('touchend', (event) => {
    if (event.changedTouches && event.changedTouches.length > 0) {
        touchEndX = event.changedTouches[0].screenX;
        handleSwipe();
    }
});

function handleSwipe() {
    const delta = touchEndX - touchStartX;
    if (Math.abs(delta) < 50) {
        return;
    }
    if (delta > 0) {
        submitResult('easy');
    } else {
        submitResult('again');
    }
}

ttsButton?.addEventListener('click', () => {
    if (!currentCard || !('speechSynthesis' in window)) {
        return;
    }
    const utterance = new SpeechSynthesisUtterance(currentCard.hebrew);
    utterance.lang = 'he-IL';
    speechSynthesis.cancel();
    speechSynthesis.speak(utterance);
});

window.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') {
        submitResult('again');
    } else if (event.key === 'ArrowRight') {
        submitResult('easy');
    } else if (event.key === 'ArrowUp') {
        submitResult('good');
    } else if (event.key === 'ArrowDown') {
        submitResult('hard');
    }
});

fetchNext();
