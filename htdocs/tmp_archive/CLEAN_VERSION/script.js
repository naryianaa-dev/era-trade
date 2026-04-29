document.addEventListener('DOMContentLoaded', () => {
    const isRu = navigator.language.startsWith('ru');
    const lang = isRu ? 'ru' : 'en';
    
    const t = {
        ru: { title: 'АКТУАЛЬНЫЕ ЛОТЫ', btn: 'ПОДРОБНЕЕ', status: 'РЕЕСТР ТОРГОВ', tg_title: 'ЛИЧНЫЙ КАБИНЕТ' },
        en: { title: 'ACTIVE LOTS', btn: 'DETAILS', status: 'AUCTION FEED', tg_title: 'DASHBOARD' }
    }[lang];

    document.querySelectorAll('[data-lang]').forEach(el => {
        const key = el.getAttribute('data-lang');
        if (t[key]) el.textContent = t[key];
    });
});