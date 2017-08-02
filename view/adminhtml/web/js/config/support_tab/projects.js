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

        var getColumns, appendCards, appendColumn;
        window.mypa.load = function () {
            var columns = getColumns();
            $.each(columns, function(key, value) {
                appendColumn(value);
            });
        };

        getColumns = function () {
            return [
                {
                    alias: "todo",
                    title: "To do"
                },
                {
                    alias: "in-progress",
                    title: "Binnenkort"
                },
                {
                    alias: "done",
                    title: "Nieuw"
                }
            ];
        };

        appendColumn = function (column) {
            $('.mypa_columns').append('<div class="myparcel_column"><h2 class="myparcel_progress_column_titel">' + column.title + '</h2><div id="label-' + column.alias + '"></div></div>');
            appendCards(column)
        };

        appendCards = function (column) {
            $.ajax({
                type: 'GET',
                url: "https://api.github.com/repos/myparcelnl/magento/issues?labels=" + column.alias + "&sort=updated-asc",
                success : function(issues) {
                    $.each(issues, function(key, issue) {
                        $('#label-' + column.alias).append('<a href="' + issue.html_url + '" target="_blank"><div class="card_item"><h3>' + issue.title + '</h3></div></a>');
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
