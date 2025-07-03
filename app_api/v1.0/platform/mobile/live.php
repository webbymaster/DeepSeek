<?php
if (!IS_LOGGED) {
    $response_data = array(
        'api_status' => '400',
        'api_version' => $api_version,
        'errors' => array(
            'error_id' => '1',
            'error_text' => 'Not logged in'
        )
    );
}
elseif (empty($_POST['type'])) {
    $response_data = array(
        'api_status' => '400',
        'api_version' => $api_version,
        'errors' => array(
            'error_id' => '4',
            'error_text' => 'type can not be empty'
        )
    );
}
else {
    if ($_POST['type'] == 'create') {
    if (empty($_POST['stream_name'])) {
        $response_data = array(
            'api_status' => '400',
            'api_version' => $api_version,
            'errors' => array(
                'error_id' => '5',
                'error_text' => 'stream_name can not be empty'
            )
        );
    } else {
        // Генерация уникального video_id
        $video_id = PT_GenerateKey(15, 15);
        while ($db->where('video_id', $video_id)->getValue(T_VIDEOS, 'count(*)') > 0) {
            $video_id = PT_GenerateKey(15, 15);
        }

        // Подготовка данных для вставки
        $insert_data = array(
            'user_id' => $pt->user->id,
            'type' => 'live',
            'title' => 'live video '.$pt->user->name,
            'stream_name' => PT_Secure($_POST['stream_name']),
            'registered' => date('Y') . '/' . intval(date('m')),
            'video_id' => $video_id,
            'time' => time(),
            'storage_type' => ($pt->config->yandex_s3_2 == 1) ? 'yandex' : 'amazon'
        );

        // Обработка токена, если есть
        if (!empty($_POST['token'])) {
            $insert_data['agora_token'] = PT_Secure($_POST['token']);
        }

        // Создание записи о видео
        $post_id = $db->insert(T_VIDEOS, $insert_data);
        PT_RunInBackground(array('status' => 200, 'post_id' => $post_id));

        // Если включено сохранение live-видео
        if ($pt->config->live_video == 1 && $pt->config->live_video_save == 1) {
            $stream_name = PT_Secure($_POST['stream_name']);
            $uid = explode('_', $stream_name)[2] ?? 0;
            $token = $insert_data['agora_token'] ?? generate_agora_token($post_id);

            // Amazon S3 запись
            if ($pt->config->amazone_s3_2 == 1 && !empty($pt->config->bucket_name_2)) {
                $region_array = array(
                    'us-east-1' => 0, 'us-east-2' => 1, 'us-west-1' => 2, 
                    'us-west-2' => 3, 'eu-west-1' => 4, 'eu-west-2' => 5,
                    'eu-west-3' => 6, 'eu-central-1' => 7, 'ap-southeast-1' => 8,
                    'ap-southeast-2' => 9, 'ap-northeast-1' => 10, 'ap-northeast-2' => 11,
                    'sa-east-1' => 12, 'ca-central-1' => 13, 'ap-south-1' => 14,
                    'cn-north-1' => 15, 'us-gov-west-1' => 17
                );

                if (isset($region_array[strtolower($pt->config->region_2)])) {
                    $result = StartCloudRecording(
                        1,
                        $region_array[strtolower($pt->config->region_2)],
                        $pt->config->bucket_name_2,
                        $pt->config->amazone_s3_key_2,
                        $pt->config->amazone_s3_s_key_2,
                        $stream_name,
                        $uid,
                        $post_id,
                        $token,
                        'amazon'
                    );
                    
                    if ($result) {
                        error_log("[MOBILE LIVE] Amazon response: " . print_r($result, true));
                        if (!empty($result->resourceId) && !empty($result->sid)) {
                            $db->where('id', $post_id)->update(T_VIDEOS, array(
                                'agora_resource_id' => $result->resourceId,
                                'agora_sid' => $result->sid,
                                'storage_type' => 'amazon'
                            ));
                            error_log("[MOBILE LIVE] Amazon recording started for video {$post_id}, SID: {$result->sid}, ResourceID: {$result->resourceId}");
                        } else {
                            error_log("[MOBILE LIVE ERROR] Invalid Amazon response for video {$post_id}");
                        }
                    } else {
                        error_log("[MOBILE LIVE ERROR] Amazon recording failed for video {$post_id}");
                    }
                }
            }
            
            // Yandex S3 запись
            if ($pt->config->yandex_s3_2 == 1 && !empty($pt->config->yandex_bucket_name_2)) {
                $result = StartCloudRecording(
                    100,
                    0,
                    $pt->config->yandex_bucket_name_2,
                    $pt->config->yandex_s3_key_2,
                    $pt->config->yandex_s3_s_key_2,
                    $stream_name,
                    $uid,
                    $post_id,
                    $token,
                    'yandex'
                );
                
                if ($result) {
                    error_log("[MOBILE LIVE] Yandex response: " . print_r($result, true));
                    if (!empty($result->resourceId) && !empty($result->sid)) {
                        $db->where('id', $post_id)->update(T_VIDEOS, array(
                            'agora_resource_id' => $result->resourceId,
                            'agora_sid' => $result->sid,
                            'storage_type' => 'yandex'
                        ));
                        error_log("[MOBILE LIVE] Yandex recording started for video {$post_id}, SID: {$result->sid}, ResourceID: {$result->resourceId}");
                    } else {
                        error_log("[MOBILE LIVE ERROR] Invalid Yandex response for video {$post_id}");
                    }
                } else {
                    error_log("[MOBILE LIVE ERROR] Yandex recording failed for video {$post_id}");
                }
            }
            
            pt_push_channel_notifiations($post_id, 'started_live_video');
        }

        $response_data = array(
            'api_status' => '200',
            'api_version' => $api_version,
            'post_id' => $post_id
        );
    }
}
    elseif ($_POST['type'] == 'check_comments') {
        if (!empty($_POST['post_id']) && is_numeric($_POST['post_id']) && $_POST['post_id'] > 0) {
            $post_id = PT_Secure($_POST['post_id']);
            $post_data = $video_data = $pt->get_video = $db->where('id', $post_id)->getOne(T_VIDEOS);
            if (!empty($post_data)) {
                if ($post_data->live_ended == 0) {
                    $user_comment = $db->where('video_id', $post_id)->where('user_id', $pt->user->id)->getOne(T_COMMENTS);
                    if (!empty($user_comment)) {
                        $db->where('id', $user_comment->id, '>');
                    }
                    if (!empty($_POST['ids'])) {
                        $ids = array();
                        foreach ($_POST['ids'] as $key => $one_id) {
                            $ids[] = PT_Secure($one_id);
                        }
                        $db->where('id', $ids, 'NOT IN')->where('id', end($ids), '>');
                    }
                    $db->where('user_id', $pt->user->id, '!=');
                    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50) ? PT_Secure($_POST['limit']) : 0;
                    $offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0) ? PT_Secure($_POST['offset']) : 0;
                    if (!empty($offset) && $offset > 0) {
                        $db->where('id', $offset, '>');
                    }

                    if (!empty($limit) && $limit > 0) {
                        $comments = $db->where('video_id', $post_id)->where('text', '', '!=')->get(T_COMMENTS, $limit);
                    }
                    else {
                        $comments = $db->where('video_id', $post_id)->where('text', '', '!=')->get(T_COMMENTS);
                    }

                    $html = '';
                    $count = 0;
                    $comments_all = array();
                    foreach ($comments as $key => $get_comment) {
                        if (!empty($get_comment->text)) {
                            $user_data = PT_UserData($get_comment->user_id);
                            unset($user_data->password);
                            $get_comment->user_data = $user_data;
                            $comments_all[] = $get_comment;
                        }
                    }

                    $word = $lang->offline;
                    $left_users_all = array();
                    $joined_users_all = array();
                    if (!empty($post_data->live_time) && $post_data->live_time >= (time() - 10)) {
                        $word = $lang->live;
                        $count = $db->where('post_id', $post_id)->where('time', time()-6, '>=')->getValue(T_LIVE_SUB, 'COUNT(*)');

                        if ($pt->user->id == $post_data->user_id) {
                            $joined_users = $db->where('post_id', $post_id)->where('time', time()-6, '>=')->where('is_watching', 0)->get(T_LIVE_SUB);
                            $joined_ids = array();
                            
                            if (!empty($joined_users)) {
                                foreach ($joined_users as $key => $value) {
                                    $joined_ids[] = $value->user_id;
                                    $user_data = PT_UserData($value->user_id);
                                    unset($user_data->password);
                                    $joined_users_all[] = $user_data;
                                }
                                if (!empty($joined_ids)) {
                                    $db->where('post_id', $post_id)->where('user_id', $joined_ids, 'IN')->update(T_LIVE_SUB, array('is_watching' => 1));
                                }
                            }

                            $left_users = $db->where('post_id', $post_id)->where('time', time()-6, '<')->where('is_watching', 1)->get(T_LIVE_SUB);
                            $left_ids = array();
                            
                            if (!empty($left_users)) {
                                foreach ($left_users as $key => $value) {
                                    $left_ids[] = $value->user_id;
                                    $user_data = PT_UserData($value->user_id);
                                    unset($user_data->password);
                                    $left_users_all[] = $user_data;
                                }
                                if (!empty($left_ids)) {
                                    $db->where('post_id', $post_id)->where('user_id', $left_ids, 'IN')->delete(T_LIVE_SUB);
                                }
                            }
                        }
                    }
                    $still_live = 'offline';
                    if (!empty($post_data) && $post_data->live_time >= (time() - 10)) {
                        $still_live = 'live';
                    }
                    $response_data = array(
                        'api_status' => 200,
                        'comments' => $comments_all,
                        'count' => $count,
                        'word' => $word,
                        'still_live' => $still_live,
                        'left' => $left_users_all,
                        'joined' => $joined_users_all,
                        'api_version' => $api_version,
                    );
                    
                    if ($pt->user->id == $post_data->user_id) {
                        if ($_POST['page'] == 'live') {
                            $time = time();
                            $db->where('id', $post_id)->update(T_VIDEOS, array('live_time' => $time));
                        }
                    }
                    else {
                        if (!empty($post_data->live_time) && $post_data->live_time >= (time() - 10) && $_POST['page'] == 'watch') {
                            $is_watching = $db->where('user_id', $pt->user->id)->where('post_id', $post_id)->getValue(T_LIVE_SUB, 'COUNT(*)');
                            if ($is_watching > 0) {
                                $db->where('user_id', $pt->user->id)->where('post_id', $post_id)->update(T_LIVE_SUB, array('time' => time()));
                            }
                            else {
                                $db->insert(T_LIVE_SUB, array(
                                    'user_id' => $pt->user->id,
                                    'post_id' => $post_id,
                                    'time' => time(),
                                    'is_watching' => 0
                                ));
                            }
                        }
                    }
                }
                else {
                    $response_data = array(
                        'api_status' => '400',
                        'api_version' => $api_version,
                        'errors' => array(
                            'error_id' => '8',
                            'error_text' => 'The live video ended'
                        )
                    );
                }
            }
            else {
                $response_data = array(
                    'api_status' => '400',
                    'api_version' => $api_version,
                    'errors' => array(
                        'error_id' => '7',
                        'error_text' => 'The video not found'
                    )
                );
            }
        }
        else {
            $response_data = array(
                'api_status' => '400',
                'api_version' => $api_version,
                'errors' => array(
                    'error_id' => '6',
                    'error_text' => 'please check your details'
                )
            );
        }
    }
    elseif ($_POST['type'] == 'delete') {
        // Проверка и подготовка ID видео
        if (empty($_POST['post_id']) || !is_numeric($_POST['post_id']) || $_POST['post_id'] <= 0) {
            $response_data = array(
                'api_status'  => '400',
                'api_version' => $api_version,
                'errors' => array(
                    'error_id' => '1',
                    'error_text' => 'Invalid video ID'
                )
            );
        } else {
            $video_id = PT_Secure($_POST['post_id']);
            error_log("[MOBILE LIVE DELETE] Starting procedure for video {$video_id}");

            // Проверяем права доступа с выборкой нужных полей
            $video = $db->where('id', $video_id)
                       ->where('user_id', $pt->user->id)
                       ->getOne(T_VIDEOS, array('id', 'user_id', 'stream_name', 'agora_token', 'agora_resource_id', 'agora_sid', 'storage_type'));

            if (empty($video)) {
                $response_data = array(
                    'api_status'  => '403',
                    'api_version' => $api_version,
                    'errors' => array(
                        'error_id' => '2',
                        'error_text' => 'Access denied or video not found'
                    )
                );
            } else {
                try {
                    // Обновляем статус видео
                    $update_data = array(
                        'live_ended' => 1,
                        'ended_at' => time()
                    );
                    
                    if (!$db->where('id', $video_id)->update(T_VIDEOS, $update_data)) {
                        throw new Exception("Failed to update video status");
                    }

                    // Обработка в зависимости от настроек сохранения
                    if ($pt->config->live_video_save == 0) {
                        PT_DeleteVideo($video_id);
                        error_log("[MOBILE LIVE DELETE] Video {$video_id} deleted (no save mode)");
                        
                        $response_data = array(
                            'api_status'  => '200',
                            'api_version' => $api_version,
                            'message' => 'Video deleted successfully',
                            'data' => array(
                                'video_id' => $video_id,
                                'saved' => false
                            )
                        );
                    } else {
                        // Подготавливаем параметры для остановки записи
                        $stop_params = array(
                            'post_id' => $video_id,
                            'cname' => $video->stream_name,
                            'uid' => explode('_', $video->stream_name)[2] ?? 0,
                            'token' => $video->agora_token ?? '',
                            'resourceId' => $video->agora_resource_id ?? '',
                            'sid' => $video->agora_sid ?? '',
                            'storage_type' => $video->storage_type ?? 'amazon'
                        );

                        error_log("[MOBILE LIVE DELETE] Stop params: " . json_encode($stop_params));
                        
                        $stop_results = array();
                        // Amazon S3
                        if ($pt->config->amazone_s3_2 == 1) {
                            $stop_params['storage_type'] = 'amazon';
                            $stop_results['amazon'] = StopCloudRecording($stop_params);
                            error_log("[MOBILE STOP] Amazon result: " . json_encode($stop_results['amazon']));
                        }
                        
                        // Yandex S3
                        if ($pt->config->yandex_s3_2 == 1) {
                            $stop_params['storage_type'] = 'yandex';
                            $stop_results['yandex'] = StopCloudRecording($stop_params);
                            error_log("[MOBILE STOP] Yandex result: " . json_encode($stop_results['yandex']));
                        }
                        
                        $response_data = array(
                            'api_status'  => '200',
                            'api_version' => $api_version,
                            'message' => 'Stream stopped successfully',
                            'data' => array(
                                'video_id' => $video_id,
                                'saved' => true,
                                'stop_results' => $stop_results
                            )
                        );
                    }
                } catch (Exception $e) {
                    error_log("[MOBILE LIVE DELETE ERROR] Video {$video_id}: " . $e->getMessage());
                    $response_data = array(
                        'api_status'  => '500',
                        'api_version' => $api_version,
                        'errors' => array(
                            'error_id' => '3',
                            'error_text' => 'Failed to process request',
                            'error_details' => $e->getMessage()
                        )
                    );
                }
            }
        }
    }
	    elseif ($_POST['type'] == 'create_thumb') {
	    // Проверка входных данных
	    if (empty($_POST['post_id']) || !is_numeric($_POST['post_id']) || $_POST['post_id'] <= 0 || empty($_FILES['thumb'])) {
	        $response_data = [
	            'api_status'  => '400',
	            'api_version' => $api_version,
	            'errors' => [
	                'error_id' => '5',
	                'error_text' => 'post_id and thumb can not be empty'
	            ]
	        ];
	    } else {
	        $post_id = PT_Secure($_POST['post_id']);
	        error_log("[MOBILE THUMB] Starting thumbnail upload for video {$post_id}");

	        // Проверяем существование видео и права доступа
	        $is_post = $db->where('id', $post_id)
	                     ->where('user_id', $pt->user->id)
	                     ->getValue(T_VIDEOS, 'COUNT(*)');
	        
	        if (!$is_post) {
	            $response_data = [
	                'api_status'  => '404',
	                'api_version' => $api_version,
	                'errors' => [
	                    'error_id' => '6',
	                    'error_text' => 'Video not found or access denied'
	                ]
	            ];
	        } else {
	            $video = $db->where('id', $post_id)->getOne(T_VIDEOS);
	            
	            // Определяем конфигурацию хранилища
	            $bucket_config = ($video->type == 'live' && $pt->config->yandex_s3_2 == 1) 
	                           ? 'yandex_s3_2' 
	                           : 'amazone_s3_2';

	            $fileInfo = [
	                'file' => $_FILES["thumb"]["tmp_name"],
	                'name' => $_FILES['thumb']['name'],
	                'size' => $_FILES["thumb"]["size"],
	                'type' => $_FILES["thumb"]["type"],
	                'types' => 'jpeg,png,jpg,gif',
	                'crop' => [
	                    'width' => 1076,
	                    'height' => 604
	                ],
	                'bucket_config' => $bucket_config,
	                'is_live' => ($video->type == 'live')
	            ];

	            error_log("[MOBILE THUMB] Uploading thumbnail with config: " . json_encode($fileInfo));
	            
	            $media = PT_ShareFile($fileInfo);
	            if (!empty($media) && !empty($media['filename'])) {
	                $update_result = $db->where('id', $post_id)
	                                  ->update(T_VIDEOS, ['thumbnail' => $media['filename']]);
	                
	                if ($update_result) {
	                    error_log("[MOBILE THUMB] Successfully uploaded thumbnail for video {$post_id}");
	                    $response_data = [
	                        'api_status'  => '200',
	                        'api_version' => $api_version,
	                        'message' => 'Thumbnail uploaded successfully',
	                        'thumbnail_url' => $media['filename']
	                    ];
	                } else {
	                    error_log("[MOBILE THUMB ERROR] Failed to update database for video {$post_id}");
	                    $response_data = [
	                        'api_status'  => '500',
	                        'api_version' => $api_version,
	                        'errors' => [
	                            'error_id' => '7',
	                            'error_text' => 'Failed to save thumbnail'
	                        ]
	                    ];
	                }
	            } else {
	                error_log("[MOBILE THUMB ERROR] Invalid file received for video {$post_id}");
	                $response_data = [
	                    'api_status'  => '400',
	                    'api_version' => $api_version,
	                    'errors' => [
	                        'error_id' => '8',
	                        'error_text' => 'Invalid thumbnail file'
	                    ]
	                ];
	            }
	        }
	    }
	}
}