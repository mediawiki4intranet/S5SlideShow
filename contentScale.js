var isGe = navigator.userAgent.indexOf('Gecko') > -1 && navigator.userAgent.indexOf('Safari') < 1 ? 1 : 0;

function contentScale(cont, hSize, vSize, initialFontSize)
{
	var fontSize = cont._lastFontSize || initialFontSize;
	var cw, ch, aspect, img, w, h;
	var sumSize = 0, sumCount = 0;
	var t;
	if (document.body.style.transform !== undefined)
		t = 't';
	else if (document.body.style.OTransform !== undefined)
		t = 'OT';
	else if (document.body.style.MozTransform !== undefined)
		t = 'MozT';
	else if (document.body.style.WebkitTransform !== undefined)
		t = 'WebkitT';
	for (var i = 0; i < 10; i++)
	{
		cw = cont.scrollWidth;
		ch = cont.scrollHeight;
		aspect = vSize/ch;
		if (aspect >= 0.95 && aspect < 1.11)
			break;
		sumSize += fontSize*aspect;
		sumCount++;
		fontSize = Math.round(sumSize*100/sumCount)/100;
		// Scale font:
		setFontSize('#'+cont.id, fontSize+'px', initialFontSize+'px');
		cont._lastFontSize = fontSize;
		// Scale images:
		var is = cont.getElementsByTagName('img');
		for (var j = 0; j < is.length; j++)
		{
			img = is[j];
			w = img.scrollWidth;
			h = img.scrollHeight;
			img.style.width = Math.round(w*aspect)+'px';
			img.style.height = Math.round(h*aspect)+'px';
		}
		// Scale SVG images:
		is = cont.getElementsByTagName('object');
		for (var j = 0; j < is.length; j++)
		{
			img = is[j];
			if (img.type != 'image/svg+xml')
				continue;
			if (!img.origWidth)
				img.origWidth = img.width;
			w = Math.round(img.width*aspect);
			h = Math.round(img.height*aspect);
			var sc = w/img.origWidth;
			var svg = img.contentDocument.documentElement;
			// Scale width and height
			svg.setAttribute('width', w);
			svg.setAttribute('height', h);
			img.width = w;
			img.height = h;
			// Scale viewBox
			var box = svg.getAttribute('viewBox');
			if (box)
			{
				box = box.split(/\s+/);
				for (var i = 0; i < box.length; i++)
					box[i] = box[i]*sc;
				svg.setAttribute('viewBox', box);
			}
			// Move SVG contents into a layer
			if (svg.childNodes.length > 1 || svg.childNodes[0].id != '_gsc')
			{
				var g = img.contentDocument.createElementNS('http://www.w3.org/2000/svg', 'g');
				g.id = '_gsc';
				while (svg.childNodes.length)
					g.appendChild(svg.childNodes[0]);
				svg.appendChild(g);
			}
			// Scale content layer
			svg = svg.childNodes[0];
			svg.setAttribute('transform', 'scale('+sc+' '+sc+')');
		}
		// Scale class="scaled" elements using CSS3 (VERY EXPERIMENTAL)
		if (t && cont.getElementsByClassName)
		{
			is = cont.getElementsByClassName('scaled');
			for (var j = 0; j < is.length; j++)
			{
				img = is[j];
				if (window.wgSlideView)
				{
					if (!img.origWidth)
						img.origWidth = img.scrollWidth;
					img.style[t+'ransformOrigin'] = '0 0';
					img.style[t+'ransform'] = 'scale('+(img.scrollWidth*aspect/img.origWidth)+')';
				}
				else
				{
					var p = img.parentNode;
					if (p.nodeName != 'DIV' || !p._aspect)
					{
						// Wrap element into a scaled <div>
						p = document.createElement('div');
						p.style[t+'ransformOrigin'] = '0 0';
						img.parentNode.insertBefore(p, img);
						p.appendChild(img);
					}
					p._aspect = aspect * (p._aspect || 1);
					p.style.height = Math.round(img.scrollHeight * p._aspect) + 'px';
					p.style.width = Math.round(img.scrollWidth * p._aspect) + 'px';
					p.style[t+'ransform'] = 'scale('+p._aspect+')';
				}
			}
		}
		reflowHack();
	}
}

function reflowHack()
{
	if (isGe)
	{  // hack to counter incremental reflow bugs
		var obj = document.getElementsByTagName('body')[0];
		obj.style.display = 'none';
		obj.style.display = 'block';
	}
}

function s5ss_addRule(target, rule, replace)
{
	if (!(s5ss = document.getElementById('s5ss')))
	{
		if (!document.createStyleSheet)
		{
			document.getElementsByTagName('head')[0].appendChild(s5ss = document.createElement('style'));
			s5ss.setAttribute('media','screen, projection');
			s5ss.setAttribute('id','s5ss');
		}
		else
		{
			document.createStyleSheet();
			document.s5ss = document.styleSheets[document.styleSheets.length - 1];
		}
	}
	if (!(document.s5ss && document.s5ss.addRule))
	{
		if (replace)
		{
			var c;
			for (var i = s5ss.childNodes.length-1; i >= 0; i--)
			{
				c = s5ss.childNodes[i];
				if (c.nodeValue.substr(0, target.length+1) == target+' ')
					s5ss.removeChild(c);
			}
		}
		s5ss.appendChild(document.createTextNode(target+' {'+rule+'}'));
	}
	else
		document.s5ss.addRule(target, rule);
}

// Force font size of all 'target' children be 'value',
// except for children which have class='scaled': set 'undoValue' for them
function setFontSize(target, value, undoValue)
{
	s5ss_addRule(target, 'font-size: ' + value + ';', true);
}
