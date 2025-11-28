





//Corrige o bug de não realizar o download

add_action('wp_footer', 'teste');

function teste() {
    echo "<script>console.log('cheguei');</script>";
}




/**
 * Expor meta fields do WPLMS via REST API
 */

// ========== BUSCAR ÚLTIMA ATIVIDADE DO BUDDYPRESS ==========

/**
 * Buscar última atividade do usuário da tabela bp_activity
 */
function wplms_get_user_last_activity($user_id) {
    global $wpdb;
    
    // Buscar a última atividade do usuário na tabela bp_activity
    $last_activity = $wpdb->get_var($wpdb->prepare(
        "SELECT date_recorded 
        FROM {$wpdb->prefix}bp_activity 
        WHERE user_id = %d 
        ORDER BY date_recorded DESC 
        LIMIT 1",
        $user_id
    ));
    
    // Log para debug
    error_log("User $user_id - Last activity from bp_activity: " . ($last_activity ?: 'NULL'));
    
    return $last_activity;
}

// ========== EXPOR META FIELDS NA API ==========

// Registrar meta fields do WPLMS para aparecer na API
add_action('rest_api_init', function() {
    // Registrar todos os meta fields que começam com 'course_status'
    register_rest_field('user', 'wplms_course_data', array(
        'get_callback' => function($user) {
            $user_id = $user['id'];
            $course_data = array();
            
            // Buscar todos os meta fields relacionados a cursos
            $all_meta = get_user_meta($user_id);
            
            foreach ($all_meta as $key => $value) {
                // Verificar se é um campo relacionado a curso do WPLMS
                if (
                    strpos($key, 'course_status') !== false ||
                    strpos($key, 'course_progress') !== false ||
                    $key === 'bp_course_ids' ||
                    strpos($key, '_course_') !== false ||
                    $key === 'last_activity'
                ) {
                    $course_data[$key] = maybe_unserialize($value[0]);
                }
            }
            
            return $course_data;
        },
        'schema' => array(
            'description' => 'WPLMS Course Data',
            'type' => 'object'
        )
    ));
});

/**
 * Endpoint customizado para buscar progresso de todos os alunos
 */
add_action('rest_api_init', function() {
    register_rest_route('wplms/v1', '/students/progress', array(
        'methods' => 'GET',
        'callback' => 'wplms_get_students_progress',
        'permission_callback' => function() {
            // Permitir acesso para requisições autenticadas via JWT ou Application Password
            // O JWT Auth plugin já valida o token automaticamente
            return true; // Permite acesso - a validação do token JWT é feita automaticamente pelo plugin
        }
    ));
});

function wplms_get_students_progress() {
    global $wpdb;
    
    // Buscar todos os usuários com role de student, subscriber, administrator, instructor
    $students = get_users(array(
        'role__in' => array('student', 'subscriber', 'administrator', 'instructor'),
        'number' => -1
    ));
    
    $result = array();
    
    foreach ($students as $student) {
        $user_id = $student->ID;
        $courses = array();
        
        // DEBUG: Buscar TODOS os meta fields do usuário para identificar padrões
        $all_meta = get_user_meta($user_id);
        $debug_meta = array();
        
        // Buscar última atividade da tabela bp_activity
        $last_activity = wplms_get_user_last_activity($user_id);
        
        // Se não encontrar no bp_activity, tentar BuddyPress user meta
        if (empty($last_activity)) {
            $last_activity = bp_get_user_last_activity($user_id);
        }
        
        // Fallback para user meta genérico
        if (empty($last_activity)) {
            $last_activity = get_user_meta($user_id, 'last_activity', true);
        }
        
        // Buscar cursos inscritos - tentar várias variações
        $enrolled_courses = get_user_meta($user_id, 'bp_course_ids', true);
        
        // Se não encontrar bp_course_ids, tentar outras variações
        if (empty($enrolled_courses)) {
            $enrolled_courses = get_user_meta($user_id, 'course_ids', true);
        }
        if (empty($enrolled_courses)) {
            $enrolled_courses = get_user_meta($user_id, 'enrolled_courses', true);
        }
        
        // Buscar diretamente no banco de dados
        if (empty($enrolled_courses)) {
            $query = $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} 
                WHERE user_id = %d AND (
                    meta_key LIKE '%course%' OR 
                    meta_key LIKE '%progress%' OR
                    meta_key LIKE '%bp_%'
                )",
                $user_id
            );
            $course_meta = $wpdb->get_results($query);
            
            foreach ($course_meta as $meta) {
                $debug_meta[$meta->meta_key] = maybe_unserialize($meta->meta_value);
            }
        }
        
        if (!empty($enrolled_courses) && is_array($enrolled_courses)) {
            foreach ($enrolled_courses as $course_id) {
                // Tentar diferentes formatos de meta keys
                $status = get_user_meta($user_id, "course_status{$course_id}", true);
                if (empty($status)) {
                    $status = get_user_meta($user_id, "course_status_{$course_id}", true);
                }
                
                $progress = get_user_meta($user_id, "course_progress{$course_id}", true);
                if (empty($progress)) {
                    $progress = get_user_meta($user_id, "course_progress_{$course_id}", true);
                }
                if (empty($progress)) {
                    $progress = get_user_meta($user_id, "course_progress", true);
                }
                
                $courses[] = array(
                    'course_id' => $course_id,
                    'status' => $status ? $status : 1,
                    'progress' => $progress ? intval($progress) : 0
                );
            }
        }
        
        $result[] = array(
            'user_id' => $user_id,
            'name' => $student->display_name,
            'email' => $student->user_email,
            'last_activity' => $last_activity ? $last_activity : null,
            'registered_date' => $student->user_registered,
            'courses' => $courses,
            'debug_meta' => $debug_meta // Incluir para debug
        );
    }
    
    return new WP_REST_Response($result, 200);
}

// Registrar meta fields protegidos para aparecer na API
add_action('rest_api_init', function() {
    register_rest_field('course', 'course_rating', array(
        'get_callback' => function($post) {
            return array(
                'average_rating' => get_post_meta($post['id'], '_course_average_rating', true),
                'review_count' => get_post_meta($post['id'], '_course_review_count', true)
            );
        },
        'schema' => array(
            'description' => 'Course rating information',
            'type' => 'object'
        )
    ));
});
add_filter('wplms_app_config', function($config){
    $primary_font = get_theme_mod('primary_font_family') ?: 'Roboto';
    $secondary_font = get_theme_mod('secondary_font_family') ?: 'Open Sans';

    $config['branding']['fonts'] = [
        'primary' => $primary_font,
        'secondary' => $secondary_font,
    ];
    return $config;
});





add_action('rest_api_init', function () {
    register_rest_route('wplms/v1', '/user/(?P<id>\d+)/points-history', array(
        'methods' => 'GET',
        'callback' => 'get_user_points_history',
        'permission_callback' => '__return_true'
    ));
});

function get_user_points_history($request) {
    global $wpdb;
    $user_id = $request['id'];
    
    // Query para buscar histórico de pontos da tabela wp_bp_activity
    $history = $wpdb->get_results($wpdb->prepare("
        SELECT 
            a.date_recorded as date,
            am_points.meta_value as points,
            am_course.meta_value as course_name,
            am_unit.meta_value as unit_name,
            a.type as activity_type
        FROM {$wpdb->prefix}bp_activity a
        LEFT JOIN {$wpdb->prefix}bp_activity_meta am_points ON am_points.activity_id = a.id AND am_points.meta_key = 'points'
        LEFT JOIN {$wpdb->prefix}bp_activity_meta am_course ON am_course.activity_id = a.id AND am_course.meta_key = 'course_name'
        LEFT JOIN {$wpdb->prefix}bp_activity_meta am_unit ON am_unit.activity_id = a.id AND am_unit.meta_key = 'unit_name'
        WHERE a.user_id = %d
        AND am_points.meta_value IS NOT NULL
        AND am_points.meta_value > 0
        ORDER BY a.date_recorded DESC
        LIMIT 50
    ", $user_id), ARRAY_A);
    
    return rest_ensure_response($history);
}



add_action('rest_api_init', function(){
  register_rest_route('wplms/v1', '/user/points-badges', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function($request){
      global $wpdb;
      
      // Decodificar JWT
      $uid = 0;
      $auth = $request->get_header('authorization');
      if ($auth && strpos($auth, 'Bearer ') === 0) {
        $token = substr($auth, 7);
        $parts = explode('.', $token);
        if (count($parts) === 3) {
          $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
          if ($payload && isset($payload['data']['user']['id'])) {
            $uid = (int) $payload['data']['user']['id'];
          }
        }
      }
      
      if (!$uid) {
        $uid = get_current_user_id();
      }
      
      if (!$uid) {
        return new WP_Error('unauthorized', 'Sem permissão', ['status' => 401]);
      }

      // Buscar DIRETO do banco
      $points_row = $wpdb->get_row($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'user_points'",
        $uid
      ));
      $points = $points_row ? (int) $points_row->meta_value : 0;

      $badges_row = $wpdb->get_row($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'vibebp_lmsbadges'",
        $uid
      ));
      $badges = [];
      if ($badges_row && $badges_row->meta_value) {
        $unserialized = @unserialize($badges_row->meta_value);
        if (is_array($unserialized)) {
          $badges = array_values($unserialized);
        }
      }

      return [
        'user_id' => $uid,
        'points'  => $points,
        'badges'  => $badges,
      ];
    }
  ]);

  // Rankings
  register_rest_route('wplms/v1', '/rankings', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function($request){
      global $wpdb;
      
      $results = $wpdb->get_results("
        SELECT u.ID, u.display_name, um.meta_value as points
        FROM {$wpdb->users} u
        LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'user_points'
        ORDER BY CAST(um.meta_value AS UNSIGNED) DESC
        LIMIT 100
      ");

      $rankings = [];
      foreach ($results as $row) {
        $rankings[] = [
          'id' => (int) $row->ID,
          'name' => $row->display_name,
          'avatar' => get_avatar_url($row->ID),
          'total_points' => (int) ($row->points ?: 0),
        ];
      }

      return ['rankings' => $rankings];
    }
  ]);
});



// Adicionar endpoint personalizado para buscar ranking com gamification_point
add_action('rest_api_init', function () {
    register_rest_route('wplms/v1', '/rankings-gamification', array(
        'methods' => 'GET',
        'callback' => 'get_rankings_with_gamification_points',
        'permission_callback' => '__return_true' // Permitir acesso autenticado via Bearer token
    ));
});

function get_rankings_with_gamification_points($request) {
    // Verificar se o usuário está autenticado via Bearer token
    $user = wp_get_current_user();
    
    if (!$user || $user->ID == 0) {
        return new WP_Error(
            'rest_forbidden', 
            'Você precisa estar autenticado para acessar este endpoint.',
            array('status' => 401)
        );
    }
    
    global $wpdb;
    
    // Query SQL para buscar usuários com gamification_point
    $query = "
        SELECT 
            u.ID AS id,
            u.user_login,
            u.display_name AS name,
            u.user_email,
            CAST(um.meta_value AS UNSIGNED) AS total_points
        FROM {$wpdb->users} u
        JOIN {$wpdb->usermeta} um 
            ON um.user_id = u.ID 
        WHERE um.meta_key = 'gamification_point'
            AND CAST(um.meta_value AS UNSIGNED) > 0
        ORDER BY CAST(um.meta_value AS UNSIGNED) DESC
    ";
    
    $results = $wpdb->get_results($query);
    
    if (empty($results)) {
        return rest_ensure_response(array(
            'rankings' => array()
        ));
    }
    
    // Adicionar avatar para cada usuário
    $rankings = array_map(function($user) {
        return array(
            'id' => intval($user->id),
            'name' => $user->name,
            'avatar' => get_avatar_url($user->id, array('size' => 96)),
            'total_points' => intval($user->total_points)
        );
    }, $results);
    
    return rest_ensure_response(array(
        'rankings' => $rankings
    ));
}


// Endpoint personalizado que retorna os pontos de gamificação
add_action('rest_api_init', function () {
    register_rest_route('wplms/v1', '/user/(?P<id>\d+)/gamification-points', array(
        'methods' => 'GET',
        'callback' => 'get_user_gamification_points',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});

function get_user_gamification_points($request) {
    $user_id = $request['id'];
    $points = get_user_meta($user_id, 'gamification_point', true);
    
    return rest_ensure_response(array(
        'user_id' => intval($user_id),
        'gamification_point' => intval($points ?: 0)
    ));
}

// Expor gamification_point no REST API do WordPress
add_action('rest_api_init', function () {
    register_rest_field('user', 'gamification_point', array(
        'get_callback' => function($user) {
            $points = get_user_meta($user['id'], 'gamification_point', true);
            return $points ? intval($points) : 0;
        },
        'schema' => array(
            'description' => 'Pontos de gamificação do usuário',
            'type' => 'integer'
        ),
    ));
});

add_filter('rest_prepare_user', function($response, $user) {
    $response->data['gamification_point'] = (int) get_user_meta($user->ID, 'gamification_point', true);
    return $response;
}, 10, 2);


add_action('init', function () {
  global $wp_post_types;
  if (isset($wp_post_types['lmsbadge'])) {
    $wp_post_types['lmsbadge']->show_in_rest = true;
    $wp_post_types['lmsbadge']->rest_base = 'lmsbadge';
    $wp_post_types['lmsbadge']->rest_controller_class = 'WP_REST_Posts_Controller';
  }
}, 20);




// /wp-json/wplms/v1/badges/requirements  (lista todos)
// /wp-json/wplms/v1/badges/requirements?ids=12,34  (apenas alguns)
// /wp-json/wplms/v1/badges/requirements?debug=1  (inclui todas as metas p/ inspeção)

add_action('rest_api_init', function () {
  register_rest_route('wplms/v1', '/badges/requirements', [
    'methods'  => 'GET',
    'args'     => [
      'ids'   => ['type'=>'string','required'=>false],   // "12,34,56"
      'debug' => ['type'=>'boolean','required'=>false],
    ],
    'permission_callback' => function () { return current_user_can('read'); },
    'callback' => function (WP_REST_Request $req) {
      $ids   = $req->get_param('ids');
      $debug = (bool)$req->get_param('debug');

      // CPTs possíveis
      $post_types = apply_filters('wplms_badge_cpts', ['lmsbadge','badge']);

      // monta query
      $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
      ];
      if (!empty($ids)) {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
        if ($ids) {
          $args['post__in'] = $ids;
          $args['orderby']  = 'post__in';
        }
      }

      $q = new WP_Query($args);
      if (!$q->have_posts()) return [];

      // chaves candidatas mais comuns
      $candidate_keys = apply_filters('wplms_badge_required_points_keys', [
        'required_points',
        'points_required',
        'badge_points',
        'lms_badge_points',
        'wplms_badge_points',
        'points',
        'points_need',
      ]);

      $out = [];
      foreach ($q->posts as $bid) {
        $used_key = null;
        $value    = null;

        // 1) tenta chaves conhecidas (na ordem)
        foreach ($candidate_keys as $k) {
          $v = get_post_meta($bid, $k, true);
          if ($v !== '' && $v !== null) {
            // normaliza número
            $num = is_numeric($v) ? 0 + $v : floatval(preg_replace('/[^\d\.]/', '', (string)$v));
            if ($num || $v === '0' || $v === 0) {
              $used_key = $k;
              $value    = $num;
              break;
            }
          }
        }

        // 2) fallback: varre todas as metas em busca de algo com "point"
        if ($used_key === null) {
          $all = get_post_meta($bid);
          foreach ($all as $k => $vv) {
            if (stripos($k, 'point') !== false && !empty($vv)) {
              $raw = is_array($vv) ? reset($vv) : $vv;
              $num = is_numeric($raw) ? 0 + $raw : floatval(preg_replace('/[^\d\.]/', '', (string)$raw));
              if ($num || $raw === '0' || $raw === 0) {
                $used_key = $k;
                $value    = $num;
                break;
              }
            }
          }
        }

        $row = [
          'badge_id'         => (int)$bid,
          'title'            => get_the_title($bid),
          'required_points'  => ($value !== null ? 0 + $value : null),
          'meta_key_usada'   => $used_key,
          'permalink'        => get_permalink($bid),
        ];

        if ($debug) {
          $row['meta'] = get_post_meta($bid); // cuidado: pode ser grande
        }

        $out[] = $row;
      }

      // ordena por pontos (quando existir), depois por título
      usort($out, function($a,$b){
        $ap = $a['required_points']; $bp = $b['required_points'];
        if ($ap === null && $bp !== null) return 1;
        if ($ap !== null && $bp === null) return -1;
        if ($ap === $bp) return strcasecmp($a['title'], $b['title']);
        return ($ap < $bp) ? -1 : 1;
      });

      return rest_ensure_response($out);
    }
  ]);
});



add_action('rest_api_init', function () {
  register_rest_route('wplms/v1', '/certificates/active', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function () {
      $uid = get_current_user_id();
      if (!$uid) return new WP_Error('not_logged', 'Faça login.', ['status' => 401]);
      if (!function_exists('bp_activity_get')) {
        return new WP_Error('no_bp', 'BuddyPress Activity não disponível.', ['status' => 500]);
      }

      // ==== 1) TIPOS DE ATIVIDADE (ajuste se sua instalação usar nomes diferentes) ====
      $award_types  = apply_filters('wplms_cert_activity_types_award',  ['course_certificate','certificate_awarded','certificate']);
      $revoke_types = apply_filters('wplms_cert_activity_types_revoke', ['certificate_revoked','course_retake','reset_course','retake_course']); 
      // Dica: se “retake” ou “reset” invalida o certificado no seu fluxo, mantenha-os aqui.

      // ==== 2) PEGAR TODAS AS CONCESSÕES DO USUÁRIO ====
      $awards_resp = bp_activity_get([
        'user_id'  => $uid,
        'filter'   => ['action' => $award_types],
        'per_page' => 999,
        'display_comments' => false,
      ]);
      $awards = $awards_resp['activities'] ?? [];

      if (!$awards) return []; // sem certificados

      // Mapa: course_id => ['date' => Y-m-d H:i:s, 'act_id' => int]
      $latest_award = [];
      foreach ($awards as $a) {
        $course_id = (int) ($a->item_id ?: $a->secondary_item_id);
        if (!$course_id) continue;
        $d = $a->date_recorded;
        if (!isset($latest_award[$course_id]) || strcmp($d, $latest_award[$course_id]['date']) > 0) {
          $latest_award[$course_id] = ['date' => $d, 'act_id' => (int)$a->id, 'type' => $a->type];
        }
      }
      if (!$latest_award) return [];

      // ==== 3) PEGAR REVOGAÇÕES/RETAKES/RESETS DO USUÁRIO ====
      $revokes_resp = bp_activity_get([
        'user_id'  => $uid,
        'filter'   => ['action' => $revoke_types],
        'per_page' => 999,
        'display_comments' => false,
      ]);
      $revokes = $revokes_resp['activities'] ?? [];

      // Indexa a última revogação por curso
      $latest_revoke = [];
      foreach ($revokes as $r) {
        $course_id = (int) ($r->item_id ?: $r->secondary_item_id);
        if (!$course_id) continue;
        $d = $r->date_recorded;
        if (!isset($latest_revoke[$course_id]) || strcmp($d, $latest_revoke[$course_id]['date']) > 0) {
          $latest_revoke[$course_id] = ['date' => $d, 'act_id' => (int)$r->id, 'type' => $r->type];
        }
      }

      // ==== 4) CONFIG DA PÁGINA CERTIFICATE (para montar URL ?code=) ====
      $cert_page_id  = (int) get_option('fallback_certificate') ?: (int) get_option('vibe_fallback_certificate');
      $cert_page_url = $cert_page_id ? get_permalink($cert_page_id) : home_url('/certificate/');

      // ==== 5) CHAVES META COMUNS ====
      $code_meta_keys   = apply_filters('wplms_cert_code_meta_keys',   ['certificate_code','code']);
      $validity_keys    = apply_filters('wplms_course_cert_validity_keys', ['certificate_validity','vibe_certificate_validity','wplms_certificate_validity']);
      $pdf_user_map_key = apply_filters('wplms_pdf_usermeta_key', 'wplms_pdf_certificates'); // [course_id => url|attachment_id]

      // Pré-carrega mapa de PDF salvo no usermeta (opcional)
      $pdf_map = get_user_meta($uid, $pdf_user_map_key, true);
      $pdf_map = is_array($pdf_map) ? $pdf_map : [];

      $now_ts = current_time('timestamp');
      $out = [];

      foreach ($latest_award as $course_id => $award) {
        // 5.1) Checa se há revogação POSTERIOR
        if (isset($latest_revoke[$course_id]) && strcmp($latest_revoke[$course_id]['date'], $award['date']) >= 0) {
          continue; // inativo por revogação/retake/reset posterior
        }

        // 5.2) Checa validade (em dias) no meta do curso
        $valid_days = null;
        foreach ($validity_keys as $k) {
          $v = get_post_meta($course_id, $k, true);
          if ($v !== '' && $v !== null) {
            $n = is_numeric($v) ? (int)$v : (int) preg_replace('/\D+/', '', (string)$v);
            if ($n || $v === '0' || $v === 0) { $valid_days = $n; break; }
          }
        }
        $award_ts = strtotime($award['date']);
        if ($valid_days && $valid_days > 0) {
          $expires_ts = $award_ts + ($valid_days * DAY_IN_SECONDS);
          if ($now_ts > $expires_ts) {
            continue; // expirado
          }
        }

        // 5.3) Recupera/gera o código do certificado
        $code = '';
        if (function_exists('bp_activity_get_meta')) {
          foreach ($code_meta_keys as $k) {
            $code = bp_activity_get_meta($award['act_id'], $k, true);
            if (!empty($code)) break;
          }
        }
        if (!$code && function_exists('apply_filters')) {
          $maybe = apply_filters('wplms_certificate_code', $uid . '_' . $course_id, $course_id, $uid);
          if (!empty($maybe)) $code = $maybe;
        }
        if (!$code) $code = md5($uid . '_' . $course_id); // último fallback

        // 5.4) Monta URL HTML (página Certificate)
        $view_url = add_query_arg('code', $code, $cert_page_url);

        // 5.5) Tenta achar PDF
        $pdf_url = '';
        if (isset($pdf_map[$course_id])) {
          $ref = $pdf_map[$course_id];
          $pdf_url = is_numeric($ref) ? wp_get_attachment_url((int)$ref) : $ref;
        }
        if (!$pdf_url) {
          // Busca attachment PDF do aluno + curso (com metadados comuns)
          $userKeys   = apply_filters('wplms_cert_pdf_user_keys',  ['certificate_user','wplms_certificate_user','_wplms_certificate_user_id','cert_user_id']);
          $courseKeys = apply_filters('wplms_cert_pdf_course_keys',['certificate_course','wplms_certificate_course','_wplms_certificate_course_id','cert_course_id']);
          $userOr = ['relation'=>'OR'];
          foreach ($userKeys as $k)   $userOr[]   = ['key'=>$k, 'value'=>$uid,       'compare'=>'='];
          $courseOr = ['relation'=>'OR'];
          foreach ($courseKeys as $k) $courseOr[] = ['key'=>$k, 'value'=>$course_id, 'compare'=>'='];
          $qpdf = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [$userOr, $courseOr],
            'author'         => $uid,
          ]);
          if ($qpdf->have_posts()) $pdf_url = wp_get_attachment_url($qpdf->posts[0]->ID);
        }

        $out[] = [
          'course_id'      => (int)$course_id,
          'course_title'   => get_the_title($course_id),
          'awarded_at'     => $award['date'],
          'valid_days'     => $valid_days,   // null = sem validade
          'url'            => $view_url,     // HTML (clicável)
          'pdf_url'        => $pdf_url ?: null,
          'activity_id'    => $award['act_id'],
          'activity_type'  => $award['type'],
        ];
      }

      // Ordena do mais recente para o mais antigo
      usort($out, fn($a,$b) => strcmp($b['awarded_at'], $a['awarded_at']));
      return rest_ensure_response($out);
    }
  ]);
});





/**
 * WPLMS Active Certificates Endpoint (JWT Compatible)
 * Retorna apenas certificados ativos com autenticação JWT
 */

add_action('rest_api_init', function () {
  register_rest_route('wplms/v1', '/certificates/active', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true', // Validaremos manualmente o JWT
    'callback' => function (WP_REST_Request $request) {
      // Validar token JWT e obter user_id
      $auth_header = $request->get_header('Authorization');
      
      if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
        return new WP_Error('no_auth', 'Token de autorização não fornecido.', ['status' => 401]);
      }
      
      $token = str_replace('Bearer ', '', $auth_header);
      
      // Validar o token JWT
      if (!function_exists('jwt_auth_validate_token')) {
        return new WP_Error('jwt_not_available', 'JWT Auth plugin não está ativo.', ['status' => 500]);
      }
      
      $validated = jwt_auth_validate_token($token, false);
      
      if (is_wp_error($validated)) {
        return new WP_Error('invalid_token', 'Token inválido ou expirado.', ['status' => 401]);
      }
      
      $uid = $validated->data->user->id ?? null;
      
      if (!$uid) {
        return new WP_Error('no_user', 'Usuário não encontrado no token.', ['status' => 401]);
      }
      
      // Array para debug
      $debug = [
        'user_id' => $uid,
        'awards_found' => 0,
        'revokes_found' => 0,
        'expired_count' => 0,
        'revoked_count' => 0,
        'active_count' => 0,
      ];
      
      if (!function_exists('bp_activity_get')) {
        return new WP_Error('no_bp', 'BuddyPress Activity não disponível.', ['status' => 500]);
      }

      // Tipos de atividade
      $award_types  = apply_filters('wplms_cert_activity_types_award',  ['course_certificate','certificate_awarded','certificate']);
      $revoke_types = apply_filters('wplms_cert_activity_types_revoke', ['certificate_revoked','course_retake','reset_course','retake_course']);

      $debug['award_types'] = $award_types;
      $debug['revoke_types'] = $revoke_types;

      // Buscar concessões de certificado
      $awards_resp = bp_activity_get([
        'user_id'  => $uid,
        'filter'   => ['action' => $award_types],
        'per_page' => 999,
        'display_comments' => false,
      ]);
      $awards = $awards_resp['activities'] ?? [];
      $debug['awards_found'] = count($awards);

      if (!$awards) {
        $debug['message'] = 'Nenhuma atividade de certificado encontrada';
        $include_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
        
        if ($include_debug) {
          return rest_ensure_response(['certificates' => [], 'debug' => $debug]);
        }
        return rest_ensure_response([]);
      }

      // Mapear última concessão por curso
      $latest_award = [];
      foreach ($awards as $a) {
        $course_id = (int) ($a->item_id ?: $a->secondary_item_id);
        if (!$course_id) continue;
        $d = $a->date_recorded;
        if (!isset($latest_award[$course_id]) || strcmp($d, $latest_award[$course_id]['date']) > 0) {
          $latest_award[$course_id] = [
            'date' => $d, 
            'act_id' => (int)$a->id, 
            'type' => $a->type,
            'course_title' => get_the_title($course_id)
          ];
        }
      }
      
      if (!$latest_award) {
        $debug['message'] = 'Nenhum certificado válido nas atividades';
        $include_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
        
        if ($include_debug) {
          return rest_ensure_response(['certificates' => [], 'debug' => $debug]);
        }
        return rest_ensure_response([]);
      }

      // Buscar revogações
      $revokes_resp = bp_activity_get([
        'user_id'  => $uid,
        'filter'   => ['action' => $revoke_types],
        'per_page' => 999,
        'display_comments' => false,
      ]);
      $revokes = $revokes_resp['activities'] ?? [];
      $debug['revokes_found'] = count($revokes);

      $latest_revoke = [];
      foreach ($revokes as $r) {
        $course_id = (int) ($r->item_id ?: $r->secondary_item_id);
        if (!$course_id) continue;
        $d = $r->date_recorded;
        if (!isset($latest_revoke[$course_id]) || strcmp($d, $latest_revoke[$course_id]['date']) > 0) {
          $latest_revoke[$course_id] = ['date' => $d, 'act_id' => (int)$r->id, 'type' => $r->type];
        }
      }

      // Configuração
      $cert_page_id  = (int) get_option('fallback_certificate') ?: (int) get_option('vibe_fallback_certificate');
      $cert_page_url = $cert_page_id ? get_permalink($cert_page_id) : home_url('/certificate/');

      $code_meta_keys   = apply_filters('wplms_cert_code_meta_keys', ['certificate_code','code']);
      $validity_keys    = apply_filters('wplms_course_cert_validity_keys', ['certificate_validity','vibe_certificate_validity','wplms_certificate_validity']);
      $pdf_user_map_key = apply_filters('wplms_pdf_usermeta_key', 'wplms_pdf_certificates');

      $pdf_map = get_user_meta($uid, $pdf_user_map_key, true);
      $pdf_map = is_array($pdf_map) ? $pdf_map : [];

      $now_ts = current_time('timestamp');
      $out = [];
      $skipped = [];

      foreach ($latest_award as $course_id => $award) {
        // Verificar revogação
        if (isset($latest_revoke[$course_id]) && strcmp($latest_revoke[$course_id]['date'], $award['date']) >= 0) {
          $debug['revoked_count']++;
          $skipped[] = [
            'course_id' => $course_id,
            'course_title' => $award['course_title'],
            'reason' => 'revoked',
            'award_date' => $award['date'],
            'revoke_date' => $latest_revoke[$course_id]['date']
          ];
          continue;
        }

        // Verificar validade
        $valid_days = null;
        foreach ($validity_keys as $k) {
          $v = get_post_meta($course_id, $k, true);
          if ($v !== '' && $v !== null) {
            $n = is_numeric($v) ? (int)$v : (int) preg_replace('/\D+/', '', (string)$v);
            if ($n || $v === '0' || $v === 0) { 
              $valid_days = $n; 
              break; 
            }
          }
        }
        
        $award_ts = strtotime($award['date']);
        if ($valid_days && $valid_days > 0) {
          $expires_ts = $award_ts + ($valid_days * DAY_IN_SECONDS);
          if ($now_ts > $expires_ts) {
            $debug['expired_count']++;
            $skipped[] = [
              'course_id' => $course_id,
              'course_title' => $award['course_title'],
              'reason' => 'expired',
              'award_date' => $award['date'],
              'valid_days' => $valid_days,
              'expired_at' => date('Y-m-d H:i:s', $expires_ts)
            ];
            continue;
          }
        }

        // Código do certificado
        $code = '';
        if (function_exists('bp_activity_get_meta')) {
          foreach ($code_meta_keys as $k) {
            $code = bp_activity_get_meta($award['act_id'], $k, true);
            if (!empty($code)) break;
          }
        }
        if (!$code) {
          $maybe = apply_filters('wplms_certificate_code', $uid . '_' . $course_id, $course_id, $uid);
          if (!empty($maybe)) $code = $maybe;
        }
        if (!$code) $code = md5($uid . '_' . $course_id);

        $view_url = add_query_arg('code', $code, $cert_page_url);

        // Buscar PDF
        $pdf_url = '';
        if (isset($pdf_map[$course_id])) {
          $ref = $pdf_map[$course_id];
          $pdf_url = is_numeric($ref) ? wp_get_attachment_url((int)$ref) : $ref;
        }
        
        if (!$pdf_url) {
          $userKeys   = ['certificate_user','wplms_certificate_user','_wplms_certificate_user_id'];
          $courseKeys = ['certificate_course','wplms_certificate_course','_wplms_certificate_course_id'];
          
          $userOr = ['relation'=>'OR'];
          foreach ($userKeys as $k) $userOr[] = ['key'=>$k, 'value'=>$uid, 'compare'=>'='];
          
          $courseOr = ['relation'=>'OR'];
          foreach ($courseKeys as $k) $courseOr[] = ['key'=>$k, 'value'=>$course_id, 'compare'=>'='];
          
          $qpdf = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [$userOr, $courseOr],
            'author'         => $uid,
          ]);
          
          if ($qpdf->have_posts()) {
            $pdf_url = wp_get_attachment_url($qpdf->posts[0]->ID);
          }
        }

        $out[] = [
          'course_id'      => (int)$course_id,
          'course_title'   => $award['course_title'],
          'awarded_at'     => $award['date'],
          'valid_days'     => $valid_days,
          'url'            => $view_url,
          'pdf_url'        => $pdf_url ?: null,
          'activity_id'    => $award['act_id'],
          'activity_type'  => $award['type'],
        ];
        $debug['active_count']++;
      }

      usort($out, fn($a,$b) => strcmp($b['awarded_at'], $a['awarded_at']));
      
      $debug['skipped_certificates'] = $skipped;
      
      $include_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
      
      if ($include_debug) {
        return rest_ensure_response([
          'certificates' => $out,
          'debug' => $debug
        ]);
      }
      
      return rest_ensure_response($out);
    }
  ]);
});



/**
 * Plugin Name: WPLMS Certificates REST (JWT + PDF)
 * Description: Endpoint REST para listar certificados do usuário no WPLMS 4, com suporte a JWT e preferência por PDF (addon WPLMS PDF Certificates).
 * Version: 1.0.0
 * Author: WPLMS Expert
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Notas rápidas
 * - Requer que a autenticação JWT já esteja configurada (ex.: VibeBP/WPLMS JWT). O plugin de JWT deve popular o usuário atual
 *   (via Authorization: Bearer <token>), de modo que is_user_logged_in() funcione aqui.
 * - Addon PDF: por padrão, tentamos gerar a URL do PDF adicionando `?pdf=1` à URL do certificado HTML. Se seu setup gerar
 *   um arquivo PDF separado, use o filtro `wplms_cert_rest_pdf_url` para ajustar.
 *
 * Endpoint principal:
 *   GET /wp-json/wplms-custom/v1/certificates
 *
 * Parâmetros opcionais:
 *   - user_id (int): se informado, só é permitido quando `current_user_can('list_users')` ou quando == usuário atual.
 *   - course_id (int): filtra por um curso específico.
 *   - format (string): 'pdf' | 'html' | 'auto' (default). O 'auto' retorna PDF quando possível, senão HTML.
 *
 * Exemplo de uso (curl):
 *   curl -H "Authorization: Bearer <JWT>" \
 *        "https://seusite.com/wp-json/wplms-custom/v1/certificates?format=auto"
 */

add_action('rest_api_init', function () {
  register_rest_route('wplms-custom/v1', '/certificates', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      // JWT deve autenticar e setar o usuário atual
      return is_user_logged_in();
    },
    'args' => [
      'user_id' => [ 'type' => 'integer', 'required' => false ],
      'course_id' => [ 'type' => 'integer', 'required' => false ],
      'format' => [ 'type' => 'string', 'required' => false, 'enum' => ['auto','pdf','html'] ],
    ],
    'callback' => 'wplms_cert_rest_list',
  ]);
});

/**
 * Callback do endpoint
 */
function wplms_cert_rest_list( WP_REST_Request $request ){
  $current_user = get_current_user_id();
  $requested_user = absint( $request->get_param('user_id') );
  $course_filter  = absint( $request->get_param('course_id') );
  $format         = $request->get_param('format');
  if ( ! in_array($format, ['auto','pdf','html'], true) ) { $format = 'auto'; }

  // Resolução do user alvo
  $user_id = $current_user;
  if ( $requested_user ) {
    if ( $requested_user === $current_user || current_user_can('list_users') ) {
      $user_id = $requested_user;
    } else {
      return new WP_REST_Response([
        'error' => 'forbidden',
        'message' => 'Você não tem permissão para consultar certificados de outro usuário.'
      ], 403);
    }
  }

  // Obter cursos do usuário via API do WPLMS, se disponível
  $courses = [];
  if ( function_exists('bp_course_get_user_courses') ) {
    $courses = (array) bp_course_get_user_courses( $user_id );
  }

  // Filtro por course_id opcional
  if ( $course_filter ) {
    $courses = array_values( array_filter($courses, function($cid) use ($course_filter){ return absint($cid) === $course_filter; }) );
  }

  $results = [];

  foreach ( $courses as $course_id ) {
    $course_id = absint($course_id);

    // Verifica se o usuário tem certificado neste curso
    $has_cert = false;
    if ( function_exists('bp_course_user_has_certificate') ) {
      $has_cert = (bool) bp_course_user_has_certificate( $user_id, $course_id );
    }
    if ( ! $has_cert ) { continue; }

    // URL HTML do certificado (página modelo do certificado)
    $html_url = '';
    if ( function_exists('bp_course_get_user_certificate') ) {
      $html_url = bp_course_get_user_certificate( $user_id, $course_id );
    }

    // Preferência por PDF (quando addon estiver presente)
    $pdf_url = '';
    if ( ! empty($html_url) ) {
      // Heurística comum do addon: adicionar ?pdf=1
      $maybe_pdf = add_query_arg( 'pdf', '1', $html_url );
      // Permita customização do formato de PDF para setups que geram arquivo separado
      $pdf_url = apply_filters('wplms_cert_rest_pdf_url', $maybe_pdf, $user_id, $course_id, $html_url );
    }

    // Data de emissão (ajuste a meta key conforme seu site)
    $issued_at = get_user_meta( $user_id, 'certificate_date_' . $course_id, true );
    if ( empty($issued_at) ) {
      // Alternativas comuns (ajuste conforme necessário)
      $issued_at = get_user_meta( $user_id, 'wplms_certificate_date_' . $course_id, true );
      if ( empty($issued_at) ) { $issued_at = null; }
    }

    // Código de validação (se o site usa validator/touchpoint)
    $code = get_user_meta( $user_id, 'certificate_code_' . $course_id, true );
    if ( empty($code) ) {
      $code = get_user_meta( $user_id, 'wplms_certificate_code_' . $course_id, true );
      if ( empty($code) ) { $code = ''; }
    }

    // Escolha final do link a retornar conforme "format"
    $certificate_url = $html_url;
    if ( 'pdf' === $format && ! empty($pdf_url) ) {
      $certificate_url = $pdf_url;
    } elseif ( 'auto' === $format ) {
      $certificate_url = ! empty($pdf_url) ? $pdf_url : $html_url;
    }

    $results[] = [
      'course_id'        => $course_id,
      'course_title'     => get_the_title( $course_id ),
      'certificate_url'  => $certificate_url,
      'html_url'         => $html_url ?: null,
      'pdf_url'          => $pdf_url ?: null,
      'issued_at'        => $issued_at,
      'code'             => $code,
    ];
  }

  // Ordena opcionalmente por data (mais recente primeiro), quando disponível
  usort($results, function($a,$b){
    $ad = $a['issued_at'] ? strtotime($a['issued_at']) : 0;
    $bd = $b['issued_at'] ? strtotime($b['issued_at']) : 0;
    return $bd <=> $ad;
  });

  return new WP_REST_Response( $results, 200 );
}

/**
 * Filtro: permita ajustar a URL do PDF caso seu ambiente gere um arquivo real em vez de `?pdf=1`.
 * Exemplo de uso no functions.php:
 *   add_filter('wplms_cert_rest_pdf_url', function($maybe_pdf, $user_id, $course_id, $html_url){
 *     // Retorne outra URL se você salvar PDFs em uploads, por exemplo.
 *     return $maybe_pdf;
 *   }, 10, 4);
 */



/**
 * WPLMS Certificates REST API Endpoint
 * GET /wp-json/wplms-custom/v1/certificates?format=auto[&user_id=...][&course_id=...]
 */

add_action('rest_api_init', function() {
  register_rest_route('wplms-custom/v1', '/certificates', [
    'methods'  => 'GET',
    'callback' => 'wplms_get_certificates_rest_simple',
    'permission_callback' => '__return_true',
    'args' => [
      'user_id' => ['required'=>false,'type'=>'integer'],
      'course_id' => ['required'=>false,'type'=>'integer'],
      'format' => ['required'=>false,'type'=>'string','default'=>'auto'],
    ]
  ]);
});

function wplms_get_certificates_rest_simple($request) {
  if ( ! is_user_logged_in() ) {
    return new WP_Error('rest_forbidden', 'JWT inválido ou ausente.', ['status' => 401]);
  }

  $current = wp_get_current_user();
  $user_id = (int)($request->get_param('user_id') ?: $current->ID);
  $format  = $request->get_param('format') ?: 'auto';
  $filter_course = $request->get_param('course_id') ? (int)$request->get_param('course_id') : null;

  if ($user_id !== (int)$current->ID && ! current_user_can('list_users')) {
    return new WP_Error('rest_forbidden','Você não tem permissão.',['status'=>403]);
  }

  // Meta 'certificates' = array serializado de IDs
  $cert_meta = get_user_meta($user_id, 'certificates', true);
  if (is_string($cert_meta)) $cert_meta = maybe_unserialize($cert_meta);
  if (!is_array($cert_meta) || empty($cert_meta)) return rest_ensure_response([]);

  if ($filter_course) {
    $cert_meta = array_values(array_filter($cert_meta, fn($cid)=> (int)$cid === $filter_course));
  }

  $items = [];

  foreach ($cert_meta as $cid) {
    $cid = (int)$cid; 
    if ($cid <= 0) continue;

    // Garante conclusão
    $status = get_user_meta($user_id, "course_status{$cid}", true);
    if ((string)$status !== '4') continue;

    $course = get_post($cid); 
    if (!$course) continue;
    
    $course_title = get_the_title($cid) ?: "Curso {$cid}";

    // Padrão WPLMS: /wp-content/uploads/YYYY/MM/{course_id}_{user_id}.pdf
    $year = date('Y');
    $month = date('m');
    $pdf_url = home_url("/wp-content/uploads/{$year}/{$month}/{$cid}_{$user_id}.pdf");
    $html_url = home_url("/certificate/{$cid}?user={$user_id}");

    // Datas/código
    $issued_at = wplms_guess_issued_at_simple($user_id, $cid, $course);
    $code = wplms_guess_code_simple($user_id, $cid);

    // Escolha por formato
    $final_url = ($format === 'pdf') ? $pdf_url : (($format === 'html') ? $html_url : $pdf_url);

    $items[] = [
      'attachment_id'  => null,
      'title'          => 'Certificado - '.$course_title,
      'file_name'      => sanitize_title($course_title).'-certificate',
      'url'            => $final_url,
      'pdf_url'        => $pdf_url,
      'certificate_url'=> $html_url,
      'course_id'      => $cid,
      'course_title'   => $course_title,
      'issued_at'      => $issued_at,
      'date'           => $issued_at,
      'code'           => $code,
      'is_active'      => true,
      'activity_type'  => 'course',
      'size_bytes'     => null,
      'valid_days'     => null,
    ];
  }

  return rest_ensure_response($items);
}

function wplms_guess_issued_at_simple($user_id,$cid,$course) {
  foreach ([
    "wplms_certificate_issued_{$cid}",
    "certificate_date_{$cid}",
    "course_completion_date{$cid}",
  ] as $k) {
    $v = get_user_meta($user_id, $k, true);
    if (!empty($v)) return $v;
  }
  return $course ? $course->post_modified : current_time('mysql');
}

function wplms_guess_code_simple($user_id,$cid) {
  foreach ([
    "wplms_certificate_number_{$cid}",
    "certificate_code_{$cid}",
  ] as $k) {
    $v = get_user_meta($user_id, $k, true);
    if (!empty($v)) return $v;
  }
  return strtoupper(substr(md5($user_id.'-'.$cid),0,12));
}




// Endpoint SSO para auto-login
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/sso-check', array(
        'methods' => 'GET',
        'callback' => 'sso_check_login',
        'permission_callback' => '__return_true'
    ));
});

function sso_check_login($request) {
    $redirect_url = $request->get_param('redirect_url');
    
    // Verifica se usuário está logado no WordPress
    if (!is_user_logged_in()) {
        // Retorna para o dashboard indicando falha de SSO
        $callback_url = add_query_arg([
            'sso_failed' => 'true',
            'wpUrl' => home_url()
        ], $redirect_url);
        
        wp_redirect($callback_url);
        exit;
    }
    
    $user = wp_get_current_user();
    
    // Gera token JWT (requer plugin JWT Authentication)
    $token = apply_filters('jwt_auth_token_before_dispatch', '', $user);
    
    if (!$token) {
        // Falha ao gerar token, volta com erro
        $callback_url = add_query_arg([
            'sso_failed' => 'true',
            'wpUrl' => home_url()
        ], $redirect_url);
        
        wp_redirect($callback_url);
        exit;
    }
    
    // Sucesso! Redireciona com token
    $callback_url = add_query_arg([
        'token' => $token,
        'wpUrl' => home_url()
    ], $redirect_url);
    
    wp_redirect($callback_url);
    exit;
}

