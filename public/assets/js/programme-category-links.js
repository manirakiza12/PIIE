/* Older homepage links used a fragment, which is never sent to the server. */
(function () {
    'use strict';
    var url = new URL(window.location.href);
    if (url.hash !== '#graduate_school' || url.searchParams.has('category') || url.searchParams.has('level')) {
        return;
    }
    url.searchParams.set('category', 'postgraduate');
    url.searchParams.delete('page');
    url.hash = 'catalogue';
    window.location.replace(url.toString());
}());
