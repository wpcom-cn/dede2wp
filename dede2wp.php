<?php
/*
 * Plugin Name: 织梦文章迁移至WP
 * Plugin URI: https://www.wpcom.cn/
 * Description: 将织梦系统文章数据迁移到WordPress
 * Author: WPCOM
 * Version: 1.1
 * Author URI: https://www.wpcom.cn/
**/


class WPCOM_DEDE2WP{
	public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_dede2wp_check_db', array($this, 'check_db'));
        add_action('wp_ajax_dede2wp_check_wp', array($this, 'check_wp'));
        add_action('wp_ajax_dede2wp_migrate_cats', array($this, 'migrate_cats'));
        add_action('wp_ajax_dede2wp_migrate_posts', array($this, 'migrate_posts'));
    }

    public function add_menu(){
		add_submenu_page('tools.php', '织梦文章迁移至WP', '织梦文章迁移', 'manage_options', 'dede2wp', array($this, 'admin_page') );
	}

	private function db($data){
		if($this->db) return $this->db;
		$this->db = new mysqli($data['dede2wp_dbhost'], $data['dede2wp_dbuser'], $data['dede2wp_dbpwd'], $data['dede2wp_dbname'], $data['dede2wp_dbport']);
		$this->language = $data['dede2wp_language'] ? 'gbk' : 'utf8';
		$this->db->set_charset($this->language);
		return $this->db;
	}

	public function check_db(){
		$data = $_POST;
	    $db = new mysqli($data['dede2wp_dbhost'], $data['dede2wp_dbuser'], $data['dede2wp_dbpwd'], $data['dede2wp_dbname'], $data['dede2wp_dbport']);
	    $language = $data['dede2wp_language'] ? 'gbk' : 'utf8';

	    $res = array(
	    	'result' => 0,
	    	'msg' => '织梦系统数据库连接测试通过！'
	    );

	    if ($db->connect_errno) {
	    	$res['msg'] = "织梦数据库连接失败：" . $db->connect_error;
	    	$res['result'] = -1;
	    }else if ( ! $db->set_charset($language)) {
	        $res['msg'] = "织梦数据库编码设置失败：" . $db->error;
	    	$res['result'] = -1;
	    }else if ( ! $db->query('SELECT * FROM `' . $data['dede2wp_dbprefix'] . 'archives` LIMIT 1')) {
	        $res['msg'] = "织梦数据库识别失败，请检查数据库表前缀是否正确";
	    	$res['result'] = -1;
	    }else if ( ($language != 'utf8' || $db->character_set_name() != 'utf8') && !function_exists('iconv')) {
	        $res['msg'] = "非utf8编码需要进行转换，请先安装PHP扩展库iconv";
	    	$res['result'] = -1;
	    }

	    echo json_encode($res);
		wp_die();
	}

	public function check_wp(){
		global $wpdb, $wp_version;
		$res = array(
	    	'result' => 0,
	    	'msg' => 'WP系统环境检测通过！'
	    );

	    if (version_compare($wp_version, '5.0', '<')) {
	    	$res = array(
		    	'result' => -1,
		    	'msg' => '请安装5.0以上版本的WordPress！'
		    );
	    }else{
	    	$posts_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->base_prefix . 'posts WHERE post_type="post" AND post_status="publish"');
			$terms_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->base_prefix . 'terms');
			if($posts_count > 1 || $terms_count > 1){
				$res = array(
			    	'result' => -1,
			    	'msg' => 'WP系统内似乎已有数据，请安装新的WP站点进行导入操作！'
			    );
			}
	    }

	    echo json_encode($res);
		wp_die();
	}

	public function migrate_cats(){
		global $wpdb;
		$this->db($_POST);

		$this->cats = array();

		if(current_user_can('manage_options')){
			$insert = $this->insert_cat(0, 0, $_POST['dede2wp_dbprefix']);
			if($insert === true){
				$res = array(
			    	'result' => 0,
			    	'msg' => '导入分类目录成功！',
			    	'cats' => $this->cats
			    );
			}else if($insert){
				$res = array(
			    	'result' => -1,
			    	'msg' => '导入分类目录出错：' . $insert
			    );
			}else{
				$res = array(
			    	'result' => -2,
			    	'msg' => '导入分类异常'
			    );
			}
		}else{
			$res = array(
		    	'result' => -3,
		    	'msg' => '当前用户没有操作权限'
		    );
		}

		echo json_encode($res);
		wp_die();
	}

	private function insert_cat($parent, $term_id, $prefix){
		$query = $this->db->query("SELECT * FROM `" . $prefix . "arctype` WHERE reid={$parent}");
		$error = '';
		while ($cat = $query->fetch_object()) {
			$slug = '';
			$dir = untrailingslashit($cat->typedir);
			$dir = $dir ? explode('/', $dir) : array();
			if(count($dir)){
				$slug = $dir[count($dir)-1];
				$_cat = get_term_by( 'slug', $slug, 'category' );
				$slug = $_cat ? '' : $slug; // 分类别名已经存在，就不设置别名了
			}
			$_c = wp_insert_term(
				$this->_iconv($cat->typename),
			    'category',
			    array(
			        'description' => $this->_iconv($cat->description),
			        'parent' => $term_id,
			        'slug' => $slug
			    )
			);
			if(is_wp_error($_c)){
				$error = $_c->get_error_message();
			}else if($_c && $_c['term_id']){
				add_term_meta( $_c['term_id'], 'wpcom_seo_keywords', $cat->keywords );
				add_term_meta( $_c['term_id'], 'wpcom_seo_title', $cat->seotitle );
				$this->cats[$cat->id] = $_c['term_id'];
				$this->insert_cat($cat->id, $_c['term_id'], $prefix);
			}
		}
		$query->close();
		return $error !== '' ? $error : true;
	}

	public function migrate_posts(){
		$res = array(
	    	'result' => 0,
	    	'msg' => ''
	    );
		if(current_user_can('manage_options')){
			global $wpdb;
			$data = $_POST;
		    $db = $this->db($data);

		    $needtag = isset($data['dede2wp_tag']) && $data['dede2wp_tag'] == '1';

		    $cats = stripslashes($data['cats']);
		    $cats = json_decode($cats, true);

		    $result = $db->query('SELECT COUNT(*) FROM `' . $data['dede2wp_dbprefix'] . 'archives` ');
		    $row = $result->fetch_row();
		    $total = (int)$row[0];

		    $paged = isset($_GET['paged']) ? $_GET['paged'] : 1;
		    $limit = $data['dede2wp_num'] ? $data['dede2wp_num'] : 100;

			$timeformat = 'Y-m-d H:i:s';

			$gmt_timezone   = new DateTimeZone('UTC');
			$local_timezone = wp_timezone();

			$current_total = $paged * $limit;
			$current_total = $current_total > $total ? $total : $current_total;
			$res['msg'] = '['.$current_total.'/'.$total.'] 导入成功！';

			if($current_total >= $total){
				$res['result'] = 1;
				$res['msg'] = '['.$current_total.'/'.$total.'] 导入完成！';
			}

		    $query = $db->query("SELECT * FROM `" . $data['dede2wp_dbprefix'] . "archives` ORDER BY `id` LIMIT " . ($paged-1)*$limit . ',' . $limit);

		    while ($post = $query->fetch_object()) {
		    	$id = $post->id;

	            $create_time = $post->pubdate;  //时间戳int
	            $update_time = $post->senddate; //时间戳int

	            //文章创建时间
	            $date = new DateTime();
	            $date->setTimestamp($create_time);
	            $post_date = $date->format($timeformat);
	            $date->setTimezone($gmt_timezone);
	            $post_date_gmt = $date->format($timeformat);

	            //文章修改时间
	            $date = new DateTime();
	            $date->setTimezone($local_timezone);
	            $date->setTimestamp($update_time);
	            $post_modified = $date->format($timeformat);
	            $date->setTimezone($gmt_timezone);
	            $post_modified_gmt = $date->format($timeformat);

	            //文章是否允许评论
	            $comment_status = $post->notpost == 1 ? "closed" : "open";

	            $content_query = $db->query("SELECT `body`, `typeid` FROM `" . $data['dede2wp_dbprefix'] . "addonarticle` WHERE `aid`= {$id} LIMIT 1");
	            $content_obj    = $content_query->fetch_object();
	            $post_content   = $this->_iconv($content_obj->body);
	            $typeid = $content_obj->typeid;
	            $content_query->close();

	            $post_title = $this->_iconv($post->title);
	            $post_name  = strtolower(urlencode($post_title));

	            $post_excerpt = $this->_iconv($post->description);

	            $guid = "";


	            $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                . "posts` (`ID`, `post_author`, `post_date`, `post_date_gmt`, `post_content`, `post_title`, 
	                `post_excerpt`, `post_status`, `comment_status`, `ping_status`, `post_password`, `post_name`, 
	                `to_ping`, `pinged`, `post_modified`, `post_modified_gmt`, `post_content_filtered`, `post_parent`,
	                `guid`, `menu_order`, `post_type`, `post_mime_type`, `comment_count`)
	                VALUES (%d, %d, %s, %s,  %s, %s, 
	                %s, 'publish', %s, 'closed', '', %s, 
	                '', '', %s, %s, '', '', 
	                %s, 0, 'post', '', 0)",
	                array(
	                    $id,
	                    get_current_user_id(),
	                    $post_date,
	                    $post_date_gmt,
	                    $post_content,
	                    $post_title,
	                    $post_excerpt,
	                    $comment_status,
	                    $post_name,
	                    $post_modified,
	                    $post_modified_gmt,
	                    $guid
	                ));
	            $wpdb->query($sql);

	            // 设置分类目录
	            if($typeid && isset($cats[$typeid])){
	            	wp_set_object_terms($id, $cats[$typeid], 'category');
	            }

	            //设置各种附加属性到 postmeta 表
	            if ($post->shorttitle) { //shorttitle 文章副标题, 简略标题
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "shorttitle", $this->_iconv($post->shorttitle)));
	                $wpdb->query($sql);
	            }

	            if ($post->color) { //color 标题颜色
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "color", $this->_iconv($post->color)));
	                $wpdb->query($sql);
	            }

	            if ($post->writer && $post->writer != 'admin' && $post->writer != 'dede') { //writer 文章作者
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "writer", $this->_iconv($post->writer)));
	                $wpdb->query($sql);
	            }

	            if ($post->source && $post->source != '未知') { //source 文章来源
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "source", $this->_iconv($post->source)));
	                $wpdb->query($sql);

	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "wpcom_original_name", $this->_iconv($post->source)));
	                $wpdb->query($sql);
	            }

	            if ($post->litpic) { // litpic 文章缩略图
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "litpic", $this->_iconv($post->litpic)));
	                $wpdb->query($sql);
	            }

	            if ($post->keywords) { // 关键字, 判断是否转换为标签
	            	if($needtag){
	            		wp_set_post_tags( $id, $this->_iconv($post->keywords), true );
	            	}else{
	            		$sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
		                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
		                    array($id, "keywords", $this->_iconv($post->keywords)));
		                $wpdb->query($sql);

		                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
		                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
		                    array($id, "wpcom_seo_keywords", $this->_iconv($post->keywords)));
		                $wpdb->query($sql);
	            	}
	            }

	            if ($post->click) { // 浏览次数
	                $sql = $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix
	                    . "postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, %s, %s)",
	                    array($id, "views", $post->click));
	                $wpdb->query($sql);
	            }
	            
		    }
		    $query->close();
		}else{
			$res = array(
		    	'result' => -3,
		    	'msg' => '当前用户没有操作权限'
		    );
		}

		echo wp_send_json($res);
		wp_die();
	}

	public function admin_page(){ ?>
		<div class="wrap">
	        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
	        <form action="" method="post" id="settings">
	            <table class="form-table">
	            	<tr>
	            		<th>数据库地址</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbhost" value="localhost">
	            			<p class="description">一般默认为localhost</p>
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据库名称</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbname" value="">
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据库用户</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbuser" value="">
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据库密码</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbpwd" value="">
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据库端口</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbport" value="3306">
	            			<p class="description">一般默认为3306</p>
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据库表前缀</th>
	            		<td>
	            			<input type="text" name="dede2wp_dbprefix" value="dede_">
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>数据编码</th>
	            		<td>
	            			<label><input type="radio" value="0" name="dede2wp_language" checked>UTF8</label>
	            			<label><input type="radio" value="1" name="dede2wp_language">GBK</label>
	            			<p class="description">请按照安装的DedeCMS版本设置</p>
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>每次导入文章</th>
	            		<td>
	            			<input type="text" name="dede2wp_num" value="100">
	            			<p class="description">为确保导入操作不中断（如请求超时，内存溢出等），请设置合理的每次导入文章数量</p>
	            		</td>
	            	</tr>
	            	<tr>
	            		<th>关键字转换为标签</th>
	            		<td>
	            			<label><input type="checkbox" name="dede2wp_tag" value="1" checked> 开启转换</label>
	            			<p class="description">是否将文章的关键字转换为标签，使用逗号分隔</p>
	            		</td>
	            	</tr>
	            </table>
	            <?php submit_button('开始导入'); ?>
	        </form>
	        <div id="process" class="process-wrap" style="display: none;"></div>
	    </div>

	    <script>
	    	(function($){
	    		$(document).ready(function(){
	    			var $form = $('#settings');
	    			var $process = $('#process');

	    			$('#wpbody-content').on('submit', '#settings', function(){
	    				var error = 0;
	    				$form.find('input[type=text],input[type=radio]:checked').each(function(i, el){
	    					if($.trim($(el).val()) === ''){
	    						alert($(el).closest('tr').find('th').text() + '不能为空！');
	    						error = 1;
	    						return false;
	    					}
	    				});

	    				if(error) return false;

	    				$form.hide();
	    				$process.show().html('<p>开始导入...【请勿中途关闭浏览器，并确保网络正常，电脑不能进入待机模式】</p>');

	    				$.ajax({
		                    url: ajaxurl + '?action=dede2wp_check_db',
		                    data: $form.serialize(),
		                    method: 'POST',
		                    dataType: 'json',
		                    success: function (data) {
		                        console.log(data,'data')
		                        if(data.result === 0){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        	check_wp();
		                        }else if(data.msg){
		                        	$process.append('<p>' + data.msg + ' <a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                        }
		                    },
		                    error: function(){
		                    	$process.append('<p>请求出错，请稍后重试！<a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                    }
		                });

	    				return false;
	    			}).on('click', '.j-dede2wp-back', function(){
	    				$process.hide();
	    				$form.show();
	    			});

	    			function check_wp(){
	    				$.ajax({
		                    url: ajaxurl + '?action=dede2wp_check_wp',
		                    method: 'POST',
		                    dataType: 'json',
		                    success: function (data) {
		                        console.log(data,'data')
		                        if(data.result === 0){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        	migrate_cats();
		                        }else if(data.msg){
		                        	$process.append('<p>' + data.msg + ' <a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                        }
		                    },
		                    error: function(){
		                    	$process.append('<p>请求出错，请稍后重试！<a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                    }
		                });
	    			}

	    			function migrate_cats() {
	    				$.ajax({
		                    url: ajaxurl + '?action=dede2wp_migrate_cats',
		                    data: $form.serialize(),
		                    method: 'POST',
		                    dataType: 'json',
		                    success: function (data) {
		                        if(data.result === 0){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        	window._cats = data.cats;
		                        	migrate_posts(1);
		                        }else if(data.result === 1){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        }else if(data.msg){
		                        	$process.append('<p>' + data.msg + ' <a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                        }
		                    },
		                    error: function(){
		                    	$process.append('<p>请求出错，请稍后重试！<a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                    }
		                });
		                setTimeout(function(){
	    					$process.append('<p>开始迁文章分类目录...</p>');
	    				}, 100);
	    			}

	    			function migrate_posts(paged){
	    				paged = paged ? paged : 1;
	    				$.ajax({
		                    url: ajaxurl + '?action=dede2wp_migrate_posts&paged='+paged,
		                    data: $form.serialize() + '&cats=' + JSON.stringify(window._cats),
		                    method: 'POST',
		                    dataType: 'json',
		                    success: function (data) {
		                        if(data.result === 0){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        	migrate_posts(paged+1);
		                        }else if(data.result === 1){
		                        	$process.append('<p>' + data.msg + '</p>');
		                        }else if(data.msg){
		                        	$process.append('<p>' + data.msg + ' <a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                        }
		                    },
		                    error: function(){
		                    	$process.append('<p>请求出错，请稍后重试！<a href="javascript:;" class="j-dede2wp-back">返回</a></p>');
		                    }
		                });
		                setTimeout(function(){
	    					$process.append('<p>开始迁移第'+paged+'页文章数据...</p>');
	    				}, 100);
	    			}
	    		});
	    	})(jQuery);
	    </script>
	<?php }
	function _iconv($str){
	    if ($this->language == 'utf8') {
	        return $str;
	    } else {
	        return iconv($this->language, 'UTF-8//IGNORE', $str);
	    }
	}
}

new WPCOM_DEDE2WP();