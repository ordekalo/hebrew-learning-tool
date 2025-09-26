(function bootstrapDirection() {
    var doc = document.documentElement;
    try {
        var storage = window.localStorage;
        var storedLang = storage ? storage.getItem('ui-lang') : null;
        var lang = storedLang || doc.lang || 'en-US';
        var isRTL = /^he|ar|fa|ur/i.test(lang);
        doc.lang = lang;
        doc.dir = isRTL ? 'rtl' : 'ltr';
    } catch (error) {
        doc.lang = 'en-US';
        doc.dir = 'ltr';
    }
}());
