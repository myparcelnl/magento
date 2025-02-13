define(
    function() {
        'use strict';

        return function Matrix(options) {
            console.warn('Matrix loaded', options);
            console.log(JSON.parse(options.carriers));
        };
    }
);
