<?php

/**
 * Class CreateAccessLogCommand
 *
 * アクセスログ作成コマンド
 */
class CreateAccessLogCommand extends BaseCommand
{
    /**
     * ビジネスロジック
     *
     * @param $args
     *
     * @return bool|mixed
     * @throws CHttpException
     */
    public function bLogic($args)
    {

        $dateFormat = 'YmdH0000';
        $key = '_sys_last_access_log_created_dt';

        // 最終処理日時を取得する
        $setting = Setting::model()->find('`key` = :key', array('key' => $key));
        $date = date($dateFormat);
        $lastProcessedDate = null;

        if ($setting) {
            $date = date($dateFormat, strtotime($setting->value) + 3600);
        } else {
            $setting = new Setting();
            $setting->key = $key;
        }
        $now = date($dateFormat);

        Yii::trace("date = {$date}");
        Yii::trace(" now = {$now}");

        while (strcmp($now, $date) > 0) {
            Yii::trace("process date = {$date}");

            // ログファイルからアクセス履歴データを登録する
            if ($this->processLog($date)) {
                $lastProcessedDate = $date;
            }

            $date = date($dateFormat, strtotime($date) + 3600);
        }

        if ($lastProcessedDate) {
            $setting->value = $lastProcessedDate;

            // 登録/更新に失敗した場合
            if (!$setting->save()) {
                Yii::log(print_r($setting->getErrors(), true), CLogger::LEVEL_ERROR);
                throw new CHttpException(500, 'Failed to create or update Setting data');
            }
        }

        return true;
    }

    /**
     * ログファイルからアクセス履歴データを登録する
     *
     * @param $date
     *
     * @return bool
     */
    private function processLog($date)
    {
        $ts = strtotime($date);
        $logFile = "{$this->logFilePath}/video-access.log." . date('Ymd', $ts) . '.' . date('H', $ts);
        Yii::trace("logFile = {$logFile}");

        if (!($fp = @fopen($logFile, 'r'))) {
            Yii::log("Log file '{$logFile}' was not found", CLogger::LEVEL_WARNING);
            return false;
        }

        $command = Yii::app()->db->createCommand();
        $command->delete(
            'access_log', "date_format(accessed_at,'%Y%m%d%H') = :date", array('date' => date('YmdH', $ts))
        );

        while ($items = fgetcsv($fp, 0, ',', '"')) {
            // 以下のURL以外へのアクセスの場合、処理しない
            // /watch（動画閲覧ページ）
            // /embed（貼付けコード）
            // /anigif（アニメーションGIF）
            if (empty($items[1]) || !preg_match('/^\/(watch|embed|anigif)/', $items[1])) {
                continue;
            }
            Yii::trace(print_r($items, true));

            $type = Constant::LOG_TYPE_VIDEO;
            if (preg_match('/^\/anigif/', $items[1])) {
                $type = Constant::LOG_TYPE_ANIGIF;
            }

            $tag = preg_replace('/^.*\/([^\/]+)$/', '$1', $items[1]);
            Yii::trace("tag = {$tag}");

            $animationGif = null;
            $animationGifHash = null;
            $animationGifSize = null;

            // 動画データを取得する
            if ($type == Constant::LOG_TYPE_VIDEO) {
                if (!($video = CachedVideo::findByBeHLSTag($tag))) {
                    continue;
                }
            } else {
                if (!($animationGif = CachedAnimationGif::findByHash($tag))) {
                    continue;
                }
                $video = $animationGif->video;
                $animationGifHash = $tag;
                $animationGifSize = $animationGif->size;
            }

            $ts = strtotime($items[0]);

            // ユーザーエージェントからデバイスを特定する
            $ua = ($items[8] == '-') ? null : $items[8];
            $device = MyHelper::detectDevice($ua);

            $referer = ($items[9] == '-') ? null : $items[9];

            $data = array(
                'account_id'         => $video->account_id,
                'video_id'           => $video->id,
                'type'               => $type,
                'title'              => $video->title,
                'video_tag'          => $video->video_tag,
                'behls_tag'          => $video->behls_tag,
                'origin_name'        => $video->origin_name,
                'size'               => $video->size ? : 0,
                'animation_gif_hash' => $animationGifHash,
                'animation_gif_size' => $animationGifSize,
                'accessed_at'        => date('YmdHis', $ts),
                'accessed_on'        => date('Ymd', $ts),
                'host'               => $items[2] ? : null,
                'ip'                 => $items[3] ? : null,
                'protocol'           => $items[4] ? : null,
                'method'             => $items[5] ? : null,
                'port'               => $items[6] ? : null,
                'http_status_code'   => $items[7] ? : null,
                'device'             => $device,
                'user_agent'         => $ua,
                'referer'            => $referer,
                'created_at'         => new CDbExpression('now()'),
            );

            $command->insert('access_log', $data);

            // アニメーションGIFの場合
            if ($type == Constant::LOG_TYPE_ANIGIF) {
                // ネットワーク転送データを登録する
                $data = array(
                    'type'             => $type,
                    'animation_gif_id' => $animationGif->id,
                    'traffic'          => $animationGif->size,
                    'ip'               => $items[3] ? : null,
                    'user_agent'       => $ua ? : null,
                    'device'           => $device,
                    'created_at'       => date('YmdHis', $ts),
                );

                $command->insert('traffic_log', $data);
            }
        }

        fclose($fp);

        return true;
    }
}
