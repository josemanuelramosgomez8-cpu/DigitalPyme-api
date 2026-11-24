document.addEventListener('DOMContentLoaded', function () {
    var printButton = document.querySelector('[data-print-trigger]');

    if (printButton) {
        printButton.addEventListener('click', function () {
            window.print();
        });
    }
});
