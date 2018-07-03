<?php
/**
 *
 * @package phpBB Extension - Mafiascum BBCodes
 * @copyright (c) 2018 mafiascum.net
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace mafiascum\bbcodes\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
/**
 * Event listener
 */
class main_listener implements EventSubscriberInterface
{
    
    /* @var \phpbb\controller\helper */
    protected $helper;

    /* @var \phpbb\template\template */
    protected $template;

    /* @var \phpbb\request\request */
    protected $request;

    /* @var \phpbb\db\driver\driver */
    protected $db;

    static public function getSubscribedEvents()
    {
        return array(

			'core.text_formatter_s9e_parse_before' => 'text_formatter_s9e_parse_before',
			'core.text_formatter_s9e_render_before' => 'text_formatter_s9e_render_before',
			'core.text_formatter_s9e_render_after' => 'text_formatter_s9e_render_after',
			'core.acp_ranks_save_modify_sql_ary' => 'acp_ranks_save_modify_sql_ary',
			'core.text_formatter_s9e_configure_after' => 'configure_bbcodes',
			'core.decode_message_before' => 'decode_message_before',
        );
    }

    /**
     * Constructor
     *
     * @param \phpbb\controller\helper	$helper		Controller helper object
     * @param \phpbb\template\template	$template	Template object
     * @param \phpbb\request\request	$request	Request object
     */
    public function __construct(\phpbb\controller\helper $helper, \phpbb\template\template $template, \phpbb\request\request $request, \phpbb\db\driver\driver_interface $db)
    {
        $this->helper = $helper;
        $this->template = $template;
        $this->request = $request;
		$this->db = $db;
	}

	public function text_formatter_s9e_parse_before($event) {

		$event['text'] = preg_replace_callback(
			'/\[post=?(.*?)\](.*?)\[\/post\]/',
			function($matches) {
				return($this->bbcode_post($matches[1], $matches[2]));
			},
			$event['text']
		);

		$event['text'] = preg_replace_callback(
			'/\[dice\](((\d+)d(\d+)(?:([\+-\/\*])(\d+))?) ?(\d+)? ?)\[\/dice\]/',
			function($matches) {
				return $this->bbcode_dice(
					$matches[3],
					$matches[4],
					$matches[1],
					$matches[2],
					$matches[5],
					$matches[6],
					$matches[7]
				);
			},
			$event['text']
		);
	}

	function configure_bbcodes($event) {
		$event['configurator']->tags['SIZE']->filterChain
		->append(array(__CLASS__, 'filter_size'));
	}

	static public function filter_size(\s9e\TextFormatter\Parser\Tag $tag) {
		
		$size = intval($tag->getAttribute('size'));
		$min_size = 10;

		if($size < $min_size)
			$tag->setAttribute('size', $min_size);

		return true;
	}

	function bbcode_post($post_number, $in) {
		global $topic_id, $db;
		$is_post_id = false;
		$error = true;
		
		if (empty($topic_id)) {
			return '[post=' . $post_number . ']' . $in . '[/post]';
		}
		if (!($post_number)) {
			$post_number=$in;
		}
		if($post_number == '') {
			return $in;
		}
		if($post_number{0} == '#') {
			
			$post_number = substr($post_number, 1);
			$is_post_id = true;
		}
		if(!preg_match('/\d+/', $post_number)) {//Must be integer.
			return '[post=' . $post_number . ']' . $in . '[/post]';
		}

		//We need for $post_number to be the internal post id.
		
		if(!$is_post_id) {
			
			$error = false;
			$sql='SET @post_count := -1;';
			$db->sql_query($sql);
			$sql = 'SELECT tmp.post_id, tmp.post_number FROM 
	        ( 
	          SELECT 
	            post_id,
	            @post_count := @post_count + 1 AS post_number
	          FROM ' . POSTS_TABLE . '
	          WHERE topic_id=' . $topic_id . '
	          ORDER BY post_time ASC
	        ) AS tmp';
			$result = $db->sql_query($sql);
			while($row = $db->sql_fetchrow($result)){
				if($row['post_number'] == $post_number){
					$dbpost_id=$row['post_id'];
				}
				else if ($row['post_number'] == 0){
					$firstpost_id=$row['post_id'];
				}
			}
			if (empty($dbpost_id)){
				$dbpost_id = $firstpost_id;
			}
			$db->sql_freeresult($result);
			$post_number = $dbpost_id;
		}
		return '[post=#' . $post_number . ']' . $in . '[/post]';
	}

	/**
	 * Parse dice tag
	 */
	function bbcode_dice($dice, $sides, $in, $inExcludingSeed, $operator, $operand, $previousSeed)
	{
		global $mode;
		
		if($dice > 100 || $sides > 500 || $dice <= 0 || $sides <= 0)
			return $in;
		
		$in = trim($in);
		$error = false;

		if($previousSeed != '') {
			$seed = $previousSeed;
		}
		else {
			mt_srand((double)microtime()*100000);
			$seed = mt_rand();
		}
		
		//Determine whether this is a static or normal dice roll.
		if($previousSeed != '' || $mode == 'edit') {//We are rolling static dice(edited post, quoted dice tag, etc)
		
			$seedString = ' ' . $seed;
		}
		else {//Normal dice roll.
		
			//$seedString = '<!--' . $seed . '-->';
			$seedString = 'SEEDSTART' . $seed . 'SEEDEND';
		}

		return '[dice]' . $inExcludingSeed . $seedString . '[/dice]';
	}

	public function text_formatter_s9e_render_after($event) {

		$event['html'] = preg_replace_callback(
			'/<span class="dice-tag-original">(.*?)<\/span>/',
			function($matches) {
				$in = $matches[1];

				if(preg_match_all('/(\d+)d(\d+)(?:([\+-\/\*])(\d+))? ?((:?SEEDSTART)?\d+(:?SEEDEND)?)/', $in, $matches_array, PREG_SET_ORDER)) {
					
					if(empty($matches_array))
						return $in;

					$inner_match = $matches_array[0];

					return $this->bbcode_second_pass_dice(
						$inner_match[1],
						$inner_match[2],
						$inner_match[5],
						$inner_match[3],
						$inner_match[4]
					);
				}

				return $in;
			},
			$event['html']
		);
	}

	function bbcode_second_pass_dice($dice, $sides, $seed, $operator, $operand)
	{
		$total = 0;
		$fixed = False;

		if($seed != '' && $seed{0} == 'S') {

			$seed = preg_replace('/SEEDSTART(\d+)SEEDEND/', '$1', $seed);
		}
		else {

			$fixed = True;
		}

		$sides = (int)$sides;
		$dice = (int)$dice;
		$seed = (int)$seed;

		mt_srand($seed);

		$buffer = '<div class="dicebox"><div class="dicetop"><emph>Original Roll String:</emph> ' . $dice . 'd' . $sides . (($operator != '' && $operand) != '' ? ($operator . $operand) : '') . ($fixed==True ? ' <fixed>(STATIC)</fixed>' : '') . '</div>'
		.      '<div class="diceroll"><emph>' . $dice . ' ' . $sides . '-Sided Dice:</emph> (';

		for($diceCounter = 0;$diceCounter < $dice;++$diceCounter) {

			$roll = mt_rand(1, $sides);
			$total += $roll;

			if($diceCounter > 0) {
				
				$buffer .= ', ';
			}

			$buffer .= $roll;
		}

		if($operator == '+')
			$total += $operand;
		else if($operator == '-')
			$total -= $operand;
		else if($operator == '*')
			$total *= $operand;
		else if($operator == '/') {
			if($operand == 0)
				$total = "INVALID";
			else
				$total /= $operand;
		}

		$buffer .= ')' . ($operator != '' && $operand != '' ? ($operator . $operand) : '') . ' = ' . $total . '</div></div>';

		return $buffer;
	}

	public function text_formatter_s9e_render_before($event) {

		global $config;

		//Used by post tag
		$script_path = rtrim($config['script_path'], '/') . '/';
		$event['renderer']->get_renderer()->setParameter("SERVER_PROTOCOL", $config['server_protocol']);
		$event['renderer']->get_renderer()->setParameter("SERVER_NAME", $config['server_name']);
		$event['renderer']->get_renderer()->setParameter("SCRIPT_PATH", $script_path);
		
		$event['xml'] = preg_replace_callback(
			'/<s>\[countdown\]\<\/s\>(.*?)<e>\[\/countdown\]<\/e>/',
			function($matches) {
				$in = $matches[1];

				if(preg_match_all('/(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d) ?(-?\d{1,2}\.\d{1,2})?/', $in, $matches_array, PREG_SET_ORDER)) {
					
					if(empty($matches_array))
						return $in;

					$inner_match = $matches_array[0];

					$year = $inner_match[1];
					$month = $inner_match[2];
					$day = $inner_match[3];
					$hour = $inner_match[4];
					$minute = $inner_match[5];
					$second = $inner_match[6];
					$timezone = $inner_match[7];

					$displayBuffer = "";
		
					if($timezone == '') {
						$gmtOffset = (-5 * 3600) + (1 * 3600);
					}
					else {
						$gmtOffset = (float)$timezone * 3600;
					}
					
					$timeNow = gmdate("U") + $gmtOffset;
					$timeDeadline = gmmktime($hour, $minute, $second, $month, $day, $year);
					$timeDiff = $timeDeadline - $timeNow;
			
					//The 'deadline' has been reached.
					if( $timeDiff <= 0 ) {
						
						$displayBuffer = "(expired on " . gmstrftime("%Y-%m-%d %H:%M:%S", $timeDeadline) . ")";
					}
					else
					{//There is still time remaining.
						$days    = (int) ($timeDiff / 60 / 60 / 24);
						$hours   = (int) (($timeDiff / 60 / 60) % 24);
						$minutes = (int) (($timeDiff / 60) % 60);
						$seconds = (int) ($timeDiff % 60);
						$displayBuffer	= "$days day"       . ($days    == 1 ? "" : "s") . ", "
								.        "$hours hour"     . ($hours   == 1 ? "" : "s") . ", "
								.        "$minutes minute" . ($minutes == 1 ? "" : "s");
					}

					return $displayBuffer;
				}

				return "[countdown]" . $in . "[/countdown]";
			},
			$event['xml']
		);
	}

	public function acp_ranks_save_modify_sql_ary($event) {
		$sql_ary = $event['sql_ary'];

		$sql_ary['rank_title'] = htmlspecialchars_decode($sql_ary['rank_title']);

		$event['sql_ary'] = $sql_ary;
	}

	public function decode_message_before($event) {

		$event['message_text'] = preg_replace_callback(
			'/<s>\[dice\]\<\/s\>(.*?)<e>\[\/dice\]<\/e>/',
			function($matches) {

				$strip_seed = strcasecmp($this->request->server('REQUEST_METHOD'), 'GET') != 0;

				if($strip_seed === TRUE)
					return '<s>[dice]</s>' . preg_replace('/SEEDSTART(\d+)SEEDEND/', '', $matches[1]) . '<e>[/dice]</e>';
				else
					return '<s>[dice]</s>' . preg_replace('/SEEDSTART(\d+)SEEDEND/', ' $1', $matches[1]) . '<e>[/dice]</e>';
			},
			$event['message_text']
		);
	}
}