<?php
namespace MediaWiki\Extension\S5SlideShow;

use Action;
use MediaWiki\MediaWikiServices;

class SlideAction extends Action {
    public function getName() {
        return 'slide';
    }

    public function requiresUnblock() {
        return false;
    }

    public function requiresWrite() {
        return false;
    }

    public function show() {
        $request = $this->getRequest();
        $s5skin = trim( $request->getVal( 's5skin', '' ) );
        $s5skin = preg_replace( '/[^\w-]/', '', $s5skin );
        
        $print = $request->getVal( 'print' );
        if ( $print ) {
            preg_match_all( '/\d+/s', $print, $matches );
            $print = $matches[0];
        } else {
            $print = false;
        }

        if ( $request->getVal( 's5css' ) ) {
            S5SlideShow::genStyle( $s5skin, $print );
            return;
        }

        $title = $this->getTitle();
        $permManager = MediaWikiServices::getInstance()->getPermissionManager();
        
        if ( !$permManager->userCan( 'read', $this->getUser(), $title ) ) {
            $this->getOutput()->showErrorPage( 'error', 'badaccess' );
            return;
        }

        $content = $request->getVal( 'wpTextbox1' );
        if ( !$content ) {
            $content = $request->getSessionData( 'wpTextbox1' );
            $request->setSessionData( 'wpTextbox1', null );
        }

        $slideShow = new S5SlideShow( $title, $content );
        if ( $s5skin ) {
            $slideShow->attr['style'] = $s5skin;
        }
        $slideShow->genSlideFile( $print );
    }
}