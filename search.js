const form = document.getElementById('search-form');
const resultsEl = document.getElementById('search-results');
const template = document.getElementById('search-item-template');

async function runSearch(event) {
    event?.preventDefault();
    if (!form) return;
    const data = new FormData(form);
    const params = new URLSearchParams();
    data.forEach((value, key) => {
        if (value instanceof File) {
            return;
        }
        if (value && value !== '') {
            params.append(key, value.toString());
        }
    });
    resultsEl.innerHTML = '<p>Searching…</p>';
    try {
        const response = await fetch(`api/search.php?${params.toString()}`, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        renderResults(payload.data || []);
    } catch (error) {
        console.error('Search failed', error);
        resultsEl.innerHTML = '<p class="error">Search failed. Try again.</p>';
    }
}

function renderResults(items) {
    resultsEl.innerHTML = '';
    if (!items.length) {
        resultsEl.innerHTML = '<p>No results found.</p>';
        return;
    }
    items.forEach((item) => {
        const clone = template.content.cloneNode(true);
        const head = clone.querySelector('.search-result__hebrew');
        const pos = clone.querySelector('.search-result__pos');
        const translit = clone.querySelector('.search-result__translit');
        const list = clone.querySelector('.search-result__translations');
        const tags = clone.querySelector('.search-result__tags');
        head.textContent = item.hebrew || '—';
        pos.textContent = item.part_of_speech || '';
        translit.textContent = item.transliteration || '';
        list.innerHTML = '';
        if (Array.isArray(item.translations) && item.translations.length) {
            item.translations.forEach((tr) => {
                const li = document.createElement('li');
                li.innerHTML = `<strong>${tr.lang_code || ''}</strong> ${tr.meaning || ''}`;
                list.append(li);
            });
        }
        tags.innerHTML = '';
        if (Array.isArray(item.tags) && item.tags.length) {
            item.tags.forEach((tag) => {
                const span = document.createElement('span');
                span.className = 'tag-chip';
                span.textContent = tag;
                tags.append(span);
            });
        }
        resultsEl.append(clone);
    });
}

form?.addEventListener('submit', runSearch);
runSearch();
