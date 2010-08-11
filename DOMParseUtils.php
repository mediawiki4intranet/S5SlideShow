<?php

/**
 * Library of functions for splitting DOM content into entitled sections and their parts.
 * (C) 2010 Vitaliy Filippov <vitalif@mail.ru>
 */

class DOMParseUtils
{
    /* Check if $mark is present inside $element. Return false when not.
       Return copy of $element with $mark removed from it when yes. */
    static function checkNode($element, $document, $mark)
    {
        $html = $document->saveXML($element);
        if ((($new = preg_replace('/^\s*((<[^<>]*>\s*)*)'.str_replace('/','\\/',preg_quote($mark)).'/is', '\1', $html)) !== $html) ||
            (($new = preg_replace('/'.str_replace('/','\\/',preg_quote($mark)).'((\s*<[^<>]*>)*)\s*$/is', '\1', $html)) !== $html))
        {
            $newdom = self::loadDOM($new);
            return $document->importNode($newdom->documentElement->childNodes->item(0)->childNodes->item(0), true);
        }
        return false;
    }

    /* Export child nodes of $element to an XML string */
    static function saveChildNodesXML($element, $document)
    {
        $xml = $document->saveXML($element, LIBXML_NOEMPTYTAG);
        $xml = preg_replace('/^\s*<[^>]*>(.*?)<\/[^\>]*>\s*$/is', '\1', $xml);
        return $xml;
    }

    /* Split DOM element by text node containing $mark inside nodeValue */
    static function splitDOM($element, $document, $mark)
    {
        $frags = array($element->cloneNode(false));
        foreach ($element->childNodes as $child)
        {
            $parts = array();
            if ($child->nodeType == XML_ELEMENT_NODE)
                $parts = self::splitDOM($child, $document, $mark);
            elseif ($child->nodeType == XML_TEXT_NODE)
            {
                $txt = preg_split('/'.str_replace('/','\\/',preg_quote($mark)).'/is', $child->nodeValue);
                if (count($txt) > 1)
                    foreach ($txt as $t)
                        $parts[] = $document->createTextNode($t);
            }
            if (count($parts) > 1)
            {
                $frags[count($frags)-1]->appendChild(array_shift($parts));
                while (count($parts))
                {
                    $e = $element->cloneNode(false);
                    $e->appendChild(array_shift($parts));
                    $frags[] = $e;
                }
            }
            else
                $frags[count($frags)-1]->appendChild($child->cloneNode(true));
        }
        return $frags;
    }

    /* Extract entitled sections from $element using DOM */
    static function getSections($element, $document, $headingmark = false)
    {
        if (!$element->childNodes->length)
            return NULL;
        $sections = array();
        $sect = NULL;
        foreach ($element->childNodes as $child)
        {
            if ($child->nodeType == XML_ELEMENT_NODE)
            {
                /* optionally check for heading mark */
                if (preg_match('/^h\d$/is', $child->nodeName) &&
                    (!$headingmark || ($child = self::checkNode($child, $document, $headingmark))))
                {
                    if ($sect)
                        $sections[] = $sect;
                    $sect = array(
                        'title'   => $child,
                        'content' => $document->createElement('slide'),
                    );
                    continue;
                }
                /* If an element contains interesting sections, it is excluded from output */
                $subslides = self::getSections($child, $document, $headingmark);
                if ($subslides)
                {
                    if ($sect)
                        $sections[] = $sect;
                    $sect = NULL;
                    $sections = array_merge($sections, $subslides);
                    continue;
                }
            }
            /* Append $child to last processed slide */
            if ($sect)
                $sect['content']->appendChild($child->cloneNode(true));
        }
        if ($sect)
            $sections[] = $sect;
        return $sections;
    }

    /* Load HTML content into a DOMDocument */
    static function loadDOM($html)
    {
        $dom = new DOMDocument();
        $oe = error_reporting();
        error_reporting($oe & ~E_WARNING);
        $dom->loadHTML("<?xml version='1.0' encoding='UTF-8'?>".mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        error_reporting($oe);
        return $dom;
    }
}
