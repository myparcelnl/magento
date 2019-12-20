/**
 *
 * Get issues from github
 * Trigger actions:
 * - Get data from github with the aliases
 * - Show the columns on the screen with a title
 * - Show inside the columns the cards
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <richard@myparcel.nl>
 * @author 		Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2017 MyParcel
 * @link        https://github.com/myparcelnl/magento
 */

require(['jquery'], function($){

    if(typeof window.mypa === 'undefined' || window.mypa === null) {
        window.mypa = {};
    }

    (function () {

        var appendCards, appendColumn;
        window.mypa.load = function () {
            appendCards();
        };

        appendColumn = function (data) {
            $('.mypa_columns').append('<div class="myparcel_column"><a href="' + data.html_url + '" target="_blank"><h2 class="myparcel_progress_column_title">' + data.name + '</h2></a></div><div id="label-' + data.id + '"></div></div>');

        };

        appendCards = function () {
            $.ajax({
                type: 'GET',
                url: 'https://api.github.com/repos/myparcelnl/magento/releases',
                success : function(data) {
                    data.slice(0, 1).forEach(function (data) {

                        appendColumn(data);

                        var arr = data.body.split('*').slice(1);

                        $.each(arr, function( index, value ) {
                            $('#label-' + data.id).append('<div class="card_item"><h2>' + value + '</h2></div>');
                        });
                    });
                }
            });
        };
    })();

    $(document).ready(function() {
        if ($(".mypa_columns")[0]){
            window.mypa.load();
        }
    });

});
