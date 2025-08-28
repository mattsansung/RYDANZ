
<?php
add_shortcode('product_cards', function($atts) {
    $template_id = 8717;

    /* ===== 解析属性：categories（不区分大小写；支持逗号/竖线分隔） ===== */
    $atts = shortcode_atts([
        'categories' => '',
    ], $atts, 'product_cards');

    $raw_cats = trim((string)$atts['categories']);
    $cat_slugs = [];
    if ($raw_cats !== '') {
        $parts = preg_split('/[,\|]/', $raw_cats);
        if (is_array($parts)) {
            $all_terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            $pool = [];
            if (!is_wp_error($all_terms) && is_array($all_terms)) {
                foreach ($all_terms as $t) { $pool[strtolower($t->name)] = $t->slug; }
            }
            foreach ($parts as $p) {
                $name = trim($p, " \t\n\r\0\x0B\"'“”‘’");
                if ($name === '') continue;
                $slug = sanitize_title($name);
                $term = get_term_by('slug', $slug, 'product_cat');
                if ($term && !is_wp_error($term)) { $cat_slugs[] = $term->slug; continue; }
                $lower = strtolower($name);
                if (isset($pool[$lower])) { $cat_slugs[] = $pool[$lower]; }
            }
            $cat_slugs = array_values(array_unique($cat_slugs));
        }
    }
	
	if ($raw_cats !== '' && empty($cat_slugs)) {
    return '<div class="pcards-empty" style="display:flex;justify-content:center;align-items:center;min-height:200px;width:100%;text-align:center;">No Product Found.</div>';
	}

    /* 载入 Elementor 必需样式 */
    if (wp_style_is('elementor-frontend', 'registered') && !wp_style_is('elementor-frontend', 'enqueued')) {
        wp_enqueue_style('elementor-frontend');
    }
    if (wp_style_is('elementor-icons', 'registered') && !wp_style_is('elementor-icons', 'enqueued')) {
        wp_enqueue_style('elementor-icons');
    }
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        $css_file = \Elementor\Core\Files\CSS\Post::create($template_id);
        if ($css_file) { $css_file->enqueue(); }
    }

    /* 查询产品（若分类存在则按分类过滤；否则显示全部） */
    $query_args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];
    if (!empty($cat_slugs)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $cat_slugs,
        ]];
    }
    $q = new WP_Query($query_args);
    if (!$q->have_posts()) {
        return '<div class="pcards-empty" style="display:flex;justify-content:center;align-items:center;min-height:200px;width:100%;text-align:center;">No Product Found.</div>';
    }

    /* 工具函数们 */

    // 注入/覆盖 background-image（保留原有属性/类）
    $inject_bg = function($html, $className, $url) {
        if (!$url) { return $html; }
        return preg_replace_callback(
            '~(<[^>]*\bclass=("|\')[^"\']*' . preg_quote($className, '~') . '[^"\']*\2[^>]*)(>)~i',
            function ($m) use ($url) {
                $open = $m[1];
                if (preg_match('~\sstyle=("|\')(.*?)\1~i', $open, $sm)) {
                    $q = $sm[1]; $style = $sm[2];
                    $style = preg_replace('~background(?:-image)?\s*:\s*[^;]+;?~i', '', $style);
                    $style = trim($style);
                    if ($style !== '' && substr($style, -1) !== ';') { $style .= ';'; }
                    $new  = $style . 'background-image:url(' . esc_url($url) . ');';
                    $open = preg_replace('~\sstyle=("|\').*?\1~i', ' style=' . $q . esc_attr($new) . $q, $open);
                } else {
                    $open .= ' style="background-image:url(' . esc_url($url) . ');"';
                }
                return $open . $m[3];
            },
            $html,
            1
        );
    };

    // 品牌图 <img src> 替换（多级兜底）
    $replace_brand_img = function($html, $url) {
        if (!$url) { return $html; }

        $out = preg_replace(
            '~(<[^>]*\bclass=("|\')[^"\']*product-card-brand-image[^"\']*\2[^>]*>[^<]*<img\s[^>]*src=["\'])([^"\']*)(["\'])~i',
            '${1}' . esc_url($url) . '${4}', $html, 1, $c1
        );
        if ($c1 > 0) return $out;

        $out = preg_replace(
            '~(<[^>]*\bdata-id=("|\')d85a1f2\2[^>]*>.*?<img\s[^>]*src=["\'])([^"\']*)(["\'])~is',
            '${1}' . esc_url($url) . '${4}', $html, 1, $c2
        );
        if ($c2 > 0) return $out;

        $out = preg_replace_callback(
            '~(<[^>]*\bdata-id=("|\')9a8dd81\2[^>]*>)(.*?)(</div>)~is',
            function($m) use ($url) {
                $inner = $m[3];
                $inner2 = preg_replace('~(<img\s[^>]*src=["\'])([^"\']*)(["\'])~i',
                                       '${1}' . esc_url($url) . '${3}', $inner, 1, $cc);
                return $m[1] . ($cc ? $inner2 : $inner) . $m[4];
            }, $html, 1, $c3
        );
        if ($c3 > 0) return $out;

        $out = preg_replace(
            '~(<div[^>]*elementor-widget-image[^>]*>[^<]*<img\s[^>]*src=["\'])([^"\']*)(["\'])~i',
            '${1}' . esc_url($url) . '${3}', $html, 1, $c4
        );
        return ($c4 > 0) ? $out : $html;
    };

    // Tag repeater：把样本节点复制多份，并替换为 tag 名
    $repeat_text_nodes = function($html, $className, $texts) {
        if (empty($texts)) { return $html; }
        $pattern = '~(<(?P<tag>[a-z0-9:-]+)[^>]*\bclass=("|\')[^"\']*' . preg_quote($className, '~') . '[^"\']*\3[^>]*>)(?P<inner>.*?)</(?P=tag)>~is';
        if (!preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) { return $html; }
        $full  = $m[0][0];
        $start = $m[0][1];
        $open  = $m[1][0];
        $tag   = $m['tag'][0];

        $pieces = [];
        foreach ($texts as $t) {
            $pieces[] = $open . '<p>' . esc_html($t) . '</p></' . $tag . '>';
        }
        return substr($html, 0, $start) . implode('', $pieces) . substr($html, $start + strlen($full));
    };

    // 描述替换：把 .product-card-des-text（或 data-id=cbf1850）内的内容替换为产品短描述（保留外层标签/类）
    $replace_des_text = function($html, $desc_html) {
        if (!$desc_html) { return $html; }
        $desc_html = wp_kses_post($desc_html);

        $pattern1 = '~(<(?P<tag>[a-z0-9:-]+)[^>]*\bclass=("|\')[^"\']*\bproduct-card-des-text\b[^"\']*\3[^>]*>)(?P<inner>.*?)</(?P=tag)>~is';
        $out = preg_replace_callback($pattern1, function($m) use ($desc_html) {
            return $m[1] . $desc_html . '</' . $m['tag'] . '>';
        }, $html, 1, $count1);
        if ($count1 > 0) return $out;

        $pattern2 = '~(<(?P<tag>[a-z0-9:-]+)[^>]*\bdata-id=("|\')cbf1850\3[^>]*>)(?P<inner>.*?)</(?P=tag)>~is';
        $out = preg_replace_callback($pattern2, function($m) use ($desc_html) {
            return $m[1] . $desc_html . '</' . $m['tag'] . '>';
        }, $html, 1, $count2);
        return ($count2 > 0) ? $out : $html;
    };

    ob_start();

    /* === 生成唯一 root 容器，用于“加载中”状态切换 === */
    $root_id = 'pcards-' . uniqid();

    /* 样式与脚本（HEREDOC，避免转义问题） */
    $styles_scripts = <<<HTML
<style>
  /* 加载中：先隐藏列表，只显示转圈 */
  .product-cards-root.pcards-loading .product-cards-wrapper { display:none !important; }
  .product-cards-root .pcards-spinner { display:none; align-items:center; justify-content:center; padding:40px 0; }
  .product-cards-root.pcards-loading .pcards-spinner { display:flex; }
  .pcards-dual-ring { width:48px; height:48px; }
  .pcards-dual-ring:after {
      content:""; display:block; width:48px; height:48px; border-radius:50%;
      border:4px solid #ccc; border-top-color:#666; animation:pcards-spin 0.9s linear infinite;
  }
  @keyframes pcards-spin { to { transform: rotate(360deg); } }

  .product-cards-wrapper { -webkit-touch-callout: none; }
  .product-cards-wrapper .elementor-widget-spacer .elementor-spacer-inner {height:var(--spacer-size,20px);}
  .product-cards-wrapper, .product-cards-wrapper * {user-select:none; -webkit-user-select:none;}
  .product-cards-wrapper img { -webkit-user-drag:none; user-drag:none; }

  .product-card-link { display:block; text-decoration:none; color:inherit; height:100%; cursor:pointer; }
  .product-card-link,
  .product-card-link *,
  .product-card-link a,
  .product-card-link a *,
  .product-card-link p,
  .product-card-link span { text-decoration: none !important; }

  .product-card-link,
  .product-card-link * {
      outline: none !important;
      box-shadow: none !important;
      -webkit-tap-highlight-color: transparent;
      tap-highlight-color: transparent;
  }
  .product-card-link:focus,
  .product-card-link:focus-visible,
  .product-card-link:active { outline:none !important; box-shadow:none !important; }
  .product-card-link *:focus,
  .product-card-link *:focus-visible,
  .product-card-link *:active { outline:none !important; box-shadow:none !important; }
</style>
<script>
document.addEventListener("DOMContentLoaded",function(){
  var root = document.getElementById("$root_id");
  if(!root) return;

  // 禁止拖拽/选择
  root.querySelectorAll(".product-cards-wrapper img").forEach(function(img){
    img.setAttribute("draggable","false");
  });
  root.addEventListener("dragstart", function(e){ e.preventDefault(); }, true);
  root.addEventListener("selectstart", function(e){ e.preventDefault(); }, true);

  // 触摸后移除焦点，防止移动端残留蓝色聚焦框
  function blurCardLink(el){
    if (!el) return;
    try { el.blur(); } catch(e){}
    var f = el.querySelector(":focus");
    if (f && f !== el) { try { f.blur(); } catch(e){} }
  }
  root.addEventListener("touchend", function(e){
    var a = e.target.closest(".product-card-link");
    if (a) { setTimeout(function(){ blurCardLink(a); }, 0); }
  }, true);
  root.addEventListener("click", function(e){
    var a = e.target.closest(".product-card-link");
    if (a) { setTimeout(function(){ blurCardLink(a); }, 0); }
  }, true);

  // ======== 预加载所有 <img> 和 background-image ========
  var urls = new Set();
  // 1) <img>
  root.querySelectorAll(".product-cards-wrapper img").forEach(function(img){
    if (img.currentSrc) { urls.add(img.currentSrc); }
    else if (img.src) { urls.add(img.src); }
  });
  // 2) 背景图
  root.querySelectorAll(".product-cards-wrapper .product-card-product-image, .product-cards-wrapper [style*=\"background-image\"]").forEach(function(el){
    var bg = (el.style && el.style.backgroundImage) ? el.style.backgroundImage : window.getComputedStyle(el).backgroundImage;
    // 提取 url(...) 中的地址，兼容单双引号或无引号
    var m = bg && bg.match(/url\\((?:'|")?(.*?)(?:'|")?\\)/);
    if (m && m[1]) { urls.add(m[1]); }
  });

  var total = urls.size;
  if (total === 0) {
    root.classList.remove("pcards-loading");
    return;
  }

  var done = 0, revealed = false;
  function maybeReveal(){
    if (revealed) return;
    if (done >= total) {
      revealed = true;
      root.classList.remove("pcards-loading");
    }
  }

  urls.forEach(function(u){
    var im = new Image();
    im.onload = im.onerror = function(){ done++; maybeReveal(); };
    try { im.referrerPolicy = "no-referrer-when-downgrade"; } catch(e){}
    im.src = u;
  });

  // 兜底：最多等待 8 秒
  setTimeout(function(){ maybeReveal(); }, 8000);
});
</script>
HTML;

    echo $styles_scripts;

    // === 外层 root：先处于 loading 态 ===
    echo '<div id="'.$root_id.'" class="product-cards-root pcards-loading">';

    // Spinner
    echo '<div class="pcards-spinner" aria-live="polite" aria-busy="true"><div class="pcards-dual-ring"></div></div>';

    // 外层容器：三列布局，列距与行距都为 2vw
    echo '<div class="product-cards-wrapper" style="display:flex;flex-wrap:wrap;column-gap:2vw;row-gap:2vw;">';

    while ($q->have_posts()) {
        $q->the_post();
        $pid = get_the_ID();

        // 主图（特色图）
        $feature_img = get_the_post_thumbnail_url($pid, 'full');

        // ACF 标题图（Field Name: title_image）
        $title_field = function_exists('get_field') ? get_field('title_image', $pid) : '';
        $title_img = '';
        if (is_array($title_field)) {
            $title_img = $title_field['url'] ?? '';
        } elseif (is_numeric($title_field)) {
            $title_img = wp_get_attachment_image_url(intval($title_field), 'full');
        } elseif (is_string($title_field)) {
            $title_img = $title_field;
        }

        // 产品所有 tags
        $terms = get_the_terms($pid, 'product_tag');
        $tag_names = is_array($terms) ? wp_list_pluck($terms, 'name') : [];

        // 短描述
        $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
        $desc_raw = $product ? $product->get_short_description() : get_post_field('post_excerpt', $pid);
        $desc_filtered = has_filter('woocommerce_short_description')
            ? apply_filters('woocommerce_short_description', $desc_raw)
            : wpautop($desc_raw);

        // 渲染模板
        $card = do_shortcode("[hfe_template id='{$template_id}']");

        // 注入主图背景 & 品牌图 & Tag repeater & 描述
        $card = $inject_bg($card, 'product-card-product-image', $feature_img);
        $card = $replace_brand_img($card, $title_img);
        if (!empty($tag_names)) { $card = $repeat_text_nodes($card, 'product-card-tag-repeater', $tag_names); }
        $card = $replace_des_text($card, $desc_filtered);

        // 整卡片可点击；三列宽度 = (100% - 2*列间距)/3 = (100% - 4vw)/3
        $permalink = get_permalink($pid);
        echo '<div class="product-card-item" style="flex:0 1 calc((100% - 4vw)/3);box-sizing:border-box;">';
        echo '<a class="product-card-link" href="' . esc_url($permalink) . '">';
        echo $card;
        echo '</a>';
        echo '</div>';
    }

    echo '</div>'; // .product-cards-wrapper
    echo '</div>'; // .product-cards-root

    wp_reset_postdata();

    return ob_get_clean();
});
