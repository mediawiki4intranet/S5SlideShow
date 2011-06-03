// JS for article view mode
// Now, scales all slides' contents to fit miniature sizes
// Miniature width is specified with window.wgSlideViewWidth, default is 200
// Miniature height is calculated from width and screen size

(function()
{
    window.wgSlideView = true;
    var f = window.wgSlideViewFont||'';
    var w = window.wgSlideViewWidth||200;
    var h = Math.round(w / screen.width * screen.height);
    s5ss_addRule('.slide',
        (f ? 'font-family: '+f+'; ' : '')+'width: '+w+'px; height: '+h+
        'px; float: left; border: 1px solid gray; text-align: center; margin: 1px; overflow: hidden;'
    );
    s5ss_addRule('.slide.withtitle { text-align: left; padding-left: 4px; }');
    s5ss_addRule('div#content .slide p', 'text-align: center;');

    window.addEventListener('load', function() {
        var sl = document.getElementsByClassName('slide');
        for (var i = 0; i < sl.length; i++)
            contentScale(sl[i], w, h, 12);
    }, false);
}) ();
