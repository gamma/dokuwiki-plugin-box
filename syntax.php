<?php
/**
 * Box Plugin: Draw highlighting boxes around wiki markup
 *
 * Syntax:     <box width% classes|title>
 *   width%    width of the box, must use % unit
 *   classes   one or more classes used to style the box, several predefined styles included in style.css
 *   padding   can be defined with each direction or as composite
 *   margin    can be defined with each direction or as composite
 *   title     (optional) all text after '|' will be rendered above the main code text with a
 *             different style.
 *
 * Acknowledgements:
 *  Rounded corners based on snazzy borders by Stu Nicholls (http://www.cssplay.co.uk/boxes/snazzy)
 *  which is in turn based on nifty corners by Alessandro Fulciniti (http://pro.html.it/esempio/nifty/)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christopher Smith <chris@jalakai.co.uk>
 * @author     i-net software <tools@inetsoftware.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_box2 extends DokuWiki_Syntax_Plugin {

    var $title_mode = false;
    var $box_content = false;

    // the following are used in rendering and are set by _xhtml_boxopen()
    var $_xb_colours      = array();
    var $_content_colours = '';
    var $_title_colours   = '';

    function getType(){ return 'protected';}
    function getAllowedTypes() { return array('container','substition','protected','disabled','formatting','paragraphs'); }
    function getPType(){ return 'block';}

    // must return a number lower than returned by native 'code' mode (200)
    function getSort(){ return 195; }

    // override default accepts() method to allow nesting
    // - ie, to get the plugin accepts its own entry syntax
    function accepts($mode) {
        if ($mode == substr(get_class($this), 7)) return true;

        return parent::accepts($mode);
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addEntryPattern('<box>(?=.*?</box.*?>)',$mode,'plugin_box2');
        $this->Lexer->addEntryPattern('<box\s[^\r\n\|]*?>(?=.*?</box.*?>)',$mode,'plugin_box2');
        $this->Lexer->addEntryPattern('<box\|(?=[^\r\n]*?\>.*?</box.*?\>)',$mode,'plugin_box2');
        $this->Lexer->addEntryPattern('<box\s[^\r\n\|]*?\|(?=[^\r\n]*?>.*?</box.*?>)',$mode,'plugin_box2');
    }

    function postConnect() {
        $this->Lexer->addPattern('>', 'plugin_box2');
        $this->Lexer->addExitPattern('</box.*?>', 'plugin_box2');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler){

        switch ($state) {
            case DOKU_LEXER_ENTER:
                $data = $this->_boxstyle(trim(substr($match, 4, -1)));
                if (substr($match, -1) == '|') {
                    $this->title_mode = true;
                    return array('title_open',$data, $pos);
                } else {
                    return array('box_open',$data, $pos);
                }

            case DOKU_LEXER_MATCHED:
                if ($this->title_mode) {
                    $this->title_mode = false;
                    return array('box_open','', $pos);
                } else {
                    return array('data', $match, $pos);
                }

            case DOKU_LEXER_UNMATCHED:
                if ($this->title_mode) {
                    return array('title', $match, $pos);
                }

                $handler->_addCall('cdata',array($match), $pos);
                return false;
            case DOKU_LEXER_EXIT:
                $pos += strlen($match); // has to be done becvause the ending tag comes after $pos
                $data = trim(substr($match, 5, -1));
                $title =  ($data && $data{0} == "|") ? substr($data,1) : '';

                return array('box_close', $title, $pos);

        }
        return false;
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $indata) {
        global $ID, $ACT;

        // $pos is for the current position in the wiki page
        if (empty($indata)) return false;
        list($instr, $data, $pos) = $indata;

        if($mode == 'xhtml'){
            switch ($instr) {
                case 'title_open' :
                    $this->title_mode = true;
                    $renderer->doc .= $this->_xhtml_boxopen($renderer, $pos, $data);
                    $renderer->doc .= '<h2 class="box_title"' . $this->_title_colours . '>';
                    break;

                case 'box_open' :
                    if ($this->title_mode) {
                        $this->title_mode = false;
                        $this->box_content = true;
                        $renderer->doc .= "</h2>\n<div class=\"box_content\"" . $this->_content_colours . '>';
                    } else {
                        $renderer->doc .= $this->_xhtml_boxopen($renderer, $pos, $data);
                        
                        if ( strlen( $this->_content_colours ) > 0 ) {
	                        $this->box_content = true;
							$renderer->doc .= '<div class="box_content"' . $this->_content_colours . '>';
						}
                    }
                    break;

                case 'title':
                case 'data' :
                    $output = $renderer->_xmlEntities($data);

                    if ( $this->title_mode ) {
                        $hid = $renderer->_headerToLink($output,true);
                        $renderer->doc .= '<a id="' . $hid . '" name="' . $hid . '">' . $output . '</a>';
                        break;
                    }
                        
                    $renderer->doc .= $output;
                    break;

                case 'box_close' :
					if ( $this->box_content ) {
                        $this->box_content = false;
	                    $renderer->doc .= "</div>\n";
					}

                    if ($data) {
                        $renderer->doc .= '<p class="box_caption"' . $this->_title_colours . '>' . $renderer->_xmlEntities($data) . "</p>\n";
                    }

                    // insert the section edit button befor the box is closed - array_pop makes sure we take the last box
                    if ( method_exists($renderer, "finishSectionEdit") ) {
                        $renderer->nocache();
                        $renderer->finishSectionEdit($pos);
                    }

                    $renderer->doc .= "\n" . $this->_xhtml_boxclose();
                        
                    break;
            }

            return true;
        }
        return false;
    }

    function _boxstyle($str) {
        if (!strlen($str)) return array();

        $styles = array();

        $tokens = preg_split('/\s+/', $str, 9);                      // limit is defensive
        foreach ($tokens as $token) {
            if (preg_match('/^\d*\.?\d+(%|px|em|ex|pt|cm|mm|pi|in)$/', $token)) {
                $styles['width'] = $token;
                continue;
            }

            if (preg_match('/^(
              (\#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}))|        #colorvalue
              (rgb\(([0-9]{1,3}%?,){2}[0-9]{1,3}%?\))     #rgb triplet
              )$/x', $token)) {
            if (preg_match('/^#[A-Za-z0-9_-]+$/', $token)) {
                $styles['id'] = substr($token, 1);
                continue;
            }
            
                $styles['colour'][] = $token;
                continue;
            }
            
            if ( preg_match('/^(margin|padding)(-(left|right|top|bottom))?:\d+(%|px|em|ex|pt|cm|mm|pi|in)$/', $token)) {
                $styles['spacing'][] = $token;
            }

            // restrict token (class names) characters to prevent any malicious data
            if (preg_match('/[^A-Za-z0-9_-]/',$token)) continue;
            $styles['class'] = (isset($styles['class']) ? $styles['class'].' ' : '').$token;
        }
        if (!empty($styles['colour'])) {
            $styles['colour'] = $this->_box_colours($styles['colour']);
        }

        return $styles;
    }

    function _box_colours($colours) {
        $triplets = array();

        // only need the first four colours
        if (count($colours) > 4) $colours = array_slice($colours,0,4);
        foreach ($colours as $colour) {
            $triplet[] = $this->_colourToTriplet($colour);
        }

        // there must be one colour to get here - the primary background
        // calculate title background colour if not present
        if (empty($triplet[1])) {
            $triplet[1] = $triplet[0];
        }

        // calculate outer background colour if not present
        if (empty($triplet[2])) {
            $triplet[2] = $triplet[0];
        }

        // calculate border colour if not present
        if (empty($triplet[3])) {
            $triplet[3] = $triplet[0];
        }

        // convert triplets back to style sheet colours
        $style_colours['content_background'] = 'rgb('.join(',',$triplet[0]).')';
        $style_colours['title_background'] = 'rgb('.join(',',$triplet[1]).')';
        $style_colours['outer_background'] = 'rgb('.join(',',$triplet[2]).')';
        $style_colours['borders'] = 'rgb('.join(',',$triplet[3]).')';

        return $style_colours;
    }

    function _colourToTriplet($colour) {
        if ($colour{0} == '#') {
            if (strlen($colour) == 4) {
                // format #FFF
                return array(hexdec($colour{1}.$colour{1}),hexdec($colour{2}.$colour{2}),hexdec($colour{3}.$colour{3}));
            } else {
                // format #FFFFFF
                return array(hexdec(substr($colour,1,2)),hexdec(substr($colour,3,2)), hexdec(substr($colour,5,2)));
            }
        } else {
            // format rgb(x,y,z)
            return explode(',',substr($colour,4,-1));
        }
    }

    function _xhtml_boxopen($renderer, $pos, $styles) {
        $class = 'class="box' . (isset($styles['class']) ? ' '.$styles['class'] : '') . (method_exists($renderer, "startSectionEdit") ? " " . $renderer->startSectionEdit($pos, array( 'target' => 'section', 'name' => 'box-' . $pos)) : "") . '"';
        $style = isset($styles['width']) ? "width: {$styles['width']};" : '';
        $style .= isset($styles['spacing']) ? implode(';', $styles['spacing']) : '';

        if (isset($styles['colour'])) {
            $style .= 'background-color:'.$styles['colour']['outer_background'].';';
            $style .= 'border-color: '.$styles['colour']['borders'].';';

            $this->_content_colours = 'style="background-color: '.$styles['colour']['content_background'].'; border-color: '.$styles['colour']['borders'].'"';
            $this->_title_colours = 'style="background-color: '.$styles['colour']['title_background'].';"';

        } else {
            $this->_content_colours = '';
            $this->_title_colours = '';
        }

        if (strlen($style)) $style = ' style="'.$style.'"';
        if (array_key_exists('id', $styles)) {
            $class = 'id="' . $styles['id'] . '" ' . $class;
        }

        $this->_xb_colours[] = $colours;

        $html = "<div $class$style>\n";
         
        // Don't do box extras if there is no style for them
        if ( !empty($colours) ) {
            $html .= '<b class="xtop"><b class="xb1"' . $colours . '>&nbsp;</b><b class="xb2"' . $colours . '">&nbsp;</b><b class="xb3"' . $colours . '>&nbsp;</b><b class="xb4"' . $colours . '>&nbsp;</b></b>' . "\n";
            $html .= '<div class="xbox"' . $colours . ">\n";
        }

        return $html;
    }

    function _xhtml_boxclose() {

        $colours = array_pop($this->_xb_colours);

        // Don't do box extras if there is no style for them
        if ( !empty($colours) ) {
            $html = "</div>\n";
            $html .= '<b class="xbottom"><b class="xb4"' . $colours .  '>&nbsp;</b><b class="xb3"' . $colours . '>&nbsp;</b><b class="xb2"' . $colours . '>&nbsp;</b><b class="xb1"' . $colours . '>&nbsp;</b></b>' . "\n";
        }
        $html .= '</div> <!-- Extras -->' . "\n";

        return $html;
    }

}

//Setup VIM: ex: et ts=4 enc=utf-8 :