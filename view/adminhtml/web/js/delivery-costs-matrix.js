define(
    function() {
        'use strict';

        return function Matrix(options) {
            console.warn('Matrix loaded', options);
            console.log(JSON.parse(options.carriers));
            console.log(JSON.parse(options.packageTypes));
            console.log(JSON.parse(options.countryParts));
        };
    }
);
