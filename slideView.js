// JS for article view mode
// Now, scales all slides' contents to fit miniature sizes
// Miniature width is specified with window.wgSlideViewWidth, default is 200
// Miniature height is calculated from width and screen size

(function()
{
    window.wgSlideView = true;
    var w, h;
    w = window.wgSlideViewWidth || 200;
    h = Math.round(w / screen.width * screen.height);
    var f = window.wgSlideViewFont||'';
    s5ss_addRule('.slide',
        (f ? 'font-family: '+f+'; ' : '')+'width: '+w+'px; height: '+h+
        'px; border: 1px solid gray; text-align: center; margin: 1px; overflow: hidden; background: #f8f8f8;'
    );
    s5ss_addRule('.slide.withtitle { text-align: left; padding-left: 4px; }');
    s5ss_addRule('div#content .slide p', 'text-align: center;');
    s5ss_addRule('.slide .noslide { display: none; }');
    s5ss_addRule('.anim.over .incremental:not(:last-child), .anim.over .previous { display: none; }');

    window.addEventListener('load', function() {
        var sl = document.getElementsByClassName('slide');
        var w, h;
        for (var i = 0; i < sl.length; i++)
        {
            w = null;
            if (sl[i].style.width)
            {
                w = sl[i].style.width;
                if (w.substr(w.length-2) == 'px')
                    w = w.substr(0, w.length-2);
                else
                    w = null;
            }
            w = w || window.wgSlideViewWidth || 200;
            h = Math.round(w / screen.width * screen.height);
            sl[i].style.width = w+'px';
            sl[i].style.height = h+'px';
            contentScale(sl[i], w, h, 12);
        }
    }, false);
}) ();
