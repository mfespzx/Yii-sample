<?php

/**
 * Class AccountController
 *
 * アカウントコントローラー
 */
class AccountController extends BaseController
{
    /**
     * 一覧ページを表示する
     */
    public function actionList()
    {
        $criteria = new CDbCriteria();
        $criteria->order = 't.id desc';
        $pages = new CPagination(Account::model()->count($criteria));
        $pages->pageSize = 30;
        $pages->applyLimit($criteria);
        $accounts = Account::model()
            ->with(array('customer' => array('select' => 'id, name')))
            ->findAll($criteria);

        $this->render('list', compact('pages', 'accounts'));
    }

    /**
     * 編集ページを表示する
     *
     * @throws CHttpException
     */
    public function actionEdit()
    {
        $model = new AccountForm();

        // POSTアクセス（確認ページの戻るボタンクリック）の場合
        if (Yii::app()->request->isPostRequest) {
            $model->attributes = $_POST['AccountForm'];
        } else {
            $id = Yii::app()->request->getParam('id');

            // idが存在する場合
            if ($id) {
                // アカウントデータを取得する
                if (!($account = Account::model()->findByPk($id))) {
                    throw new CHttpException(404, 'Account data was not found');
                }

                foreach ($account->attributes as $key => $value) {
                    if (array_key_exists($key, $model->attributes)) {
                        $model->{$key} = $value;
                    }
                }

                // パスワードを復号化する
                $model->password = MyCrypt::decrypt($model->password);

                // ディスク容量、ネットワーク転送量をGB単位に変換する
                $model->disk_quota_gb = $model->disk_quota / pow(1024, 3);

                // 契約種類が従量課金以外の場合
                if ($model->contract_type != Constant::CONTRACT_TYPE_SPECIFIC) {
                    $model->traffic_quota_gb = $model->traffic_quota / pow(1024, 3);
                }

                if (!empty($account->availabled_on)) {
                    $model->availabled_on = date('Y/m/d', strtotime($account->availabled_on));
                }
                if (!empty($account->unavailabled_on)) {
                    $model->unavailabled_on = date('Y/m/d', strtotime($account->unavailabled_on));
                }
            } else {
                $model->send_mail = true;
            }
        }

        $this->render('edit', compact('model'));
    }

    /**
     * 確認ページを表示する
     */
    public function actionConfirm()
    {
        // POSTアクセス判定
        $this->isPostAccess();

        $model = new AccountForm();
        $model->attributes = $_POST['AccountForm'];

        // 入力チェックエラーの場合、編集ページを再表示する
        if (!$model->validate()) {
            $this->render('edit', compact('model'));
            return;
        }

        $customer = Customer::model()->findByPk($model->customer_id);
        $model->customer_name = $customer->name;

        $this->render('confirm', compact('model'));
    }

    /**
     * 登録/更新処理を実行し完了ページを表示する
     */
    public function actionSave()
    {
        // POSTアクセス判定
        $this->isPostAccess();

        $model = new AccountForm();
        $model->attributes = $_POST['AccountForm'];

        // 登録/更新処理を実行する
        $account = $model->save();

        Yii::app()->adminUser->setFlash($model->id ? 'update' : 'create', !empty($account));

        if ($account) {
            $this->redirect("/admin/account/edit?id={$account->id}");
        }

        $this->render('edit', compact('model'));
    }

    /**
     * 削除処理を実行する
     */
    public function actionDelete()
    {
        // POSTアクセス判定
        $this->isPostAccess();

        $model = new AccountForm();
        $model->attributes = $_POST['AccountForm'];
        $model->scenario = 'delete';

        // 入力チェックエラーの場合、編集ページを再表示する
        if (!$model->validate()) {
            $this->render('edit', compact('model'));
            return;
        }

        // 削除処理を実行する
        $account = $model->delete();

        Yii::app()->adminUser->setFlash('delete', !empty($account));
        $this->redirect('/admin/account/list');
    }
}
