<?php

namespace app\controllers;

use yii;
use yii\data\Pagination;
use app\components\Controller;
use app\models\Task;
use app\models\Project;
use app\models\User;
use app\models\Group;

class TaskController extends Controller {
    
    protected $task;

    public function actionIndex($page = 1, $size = 10) {
        $size = $this->getParam('per-page') ?: $size;
        $list = Task::find()
            ->with('user')
            ->with('project')
            ->where(['user_id' => $this->uid]);

        // 有审核权限的任务
        $auditProjects = Group::getAuditProjectIds($this->uid);
        if ($auditProjects) {
            $list->orWhere(['project_id' => $auditProjects]);
        }


        $kw = \Yii::$app->request->post('kw');
        if ($kw) {
            $list->andWhere(['or', "commit_id like '%" . $kw . "%'", "title like '%" . $kw . "%'"]);
        }
        $tasks = $list->orderBy('id desc');
        $list = $tasks->offset(($page - 1) * $size)->limit(10)
            ->asArray()->all();

        $pages = new Pagination(['totalCount' => $tasks->count(), 'pageSize' => 10]);
        return $this->render('list', [
            'list'  => $list,
            'pages' => $pages,
            'audit' => $auditProjects,
        ]);
    }


    /**
     * 提交任务
     *
     * @param $projectId
     * @return string
     */
    public function actionSubmit($projectId = null) {
        $task = new Task();
        if ($projectId) {
            $conf = Project::find()
                ->where(['id' => $projectId, 'status' => Project::STATUS_VALID])
                ->one();
        }
        if (\Yii::$app->request->getIsPost()) {
            if (!$conf) throw new \Exception(yii::t('task', 'unknown project'));
            $group = Group::find()
                ->where(['user_id' => $this->uid, 'project_id' => $projectId])
                ->count();
            if (!$group) throw new \Exception(yii::t('task', 'you are not the member of project'));

            if ($task->load(\Yii::$app->request->post())) {
                // 是否需要审核
                $status = $conf->audit == Project::AUDIT_YES ? Task::STATUS_SUBMIT : Task::STATUS_PASS;
                $task->user_id = $this->uid;
                $task->project_id = $projectId;
                $task->status = $status;
                if ($task->save()) {
                    return $this->redirect('/task/');
                }
            }
        }
        if ($projectId) {
            $tpl = $conf->repo_type == Project::REPO_GIT ? 'submit-git' : 'submit-svn';
            return $this->render($tpl, [
                'task' => $task,
                'conf' => $conf,
            ]);
        }
        // 成员所属项目
        $projects = Project::find()
            ->leftJoin(Group::tableName(), '`group`.project_id=project.id')
            ->where(['project.status' => Project::STATUS_VALID, '`group`.user_id' => $this->uid])
            ->asArray()->all();
        return $this->render('select-project', [
            'projects' => $projects,
        ]);
    }


    /**
     * 任务删除
     *
     * @return string
     * @throws \Exception
     */
    public function actionDelete($taskId) {
        $task = Task::findOne($taskId);
        if (!$task) {
            throw new \Exception(yii::t('task', 'unknown deployment bill'));
        }
        if ($task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }
        if ($task->status == Task::STATUS_DONE) {
            throw new \Exception(yii::t('task', 'can\'t delele the job which is done'));
        }
        if (!$task->delete()) throw new \Exception(yii::t('w', 'delete failed'));
        $this->renderJson([]);

    }

    /**
     * 生成回滚任务
     *
     * @return string
     * @throws \Exception
     */
    public function actionRollback($taskId) {
        $this->task = Task::findOne($taskId);
        if (!$this->task) {
            throw new \Exception(yii::t('task', 'unknown deployment bill'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }
        if ($this->task->ex_link_id == $this->task->link_id) {
            throw new \Exception(yii::t('task', 'no rollback twice'));
        }
        $conf = Project::find()
            ->where(['id' => $this->task->project_id, 'status' => Project::STATUS_VALID])
            ->one();
        if (!$conf) {
            throw new \Exception(yii::t('task', 'can\'t rollback the closed project\'s job'));
        }

        // 是否需要审核
        $status = $conf->audit == Project::AUDIT_YES ? Task::STATUS_SUBMIT : Task::STATUS_PASS;

        $rollbackTask = new Task();
        $rollbackTask->attributes = [
            'user_id' => $this->uid,
            'project_id' => $this->task->project_id,
            'status' => $status,
            'action' => Task::ACTION_ROLLBACK,
            'link_id' => $this->task->ex_link_id,
            'ex_link_id' => $this->task->ex_link_id,
            'title' => $this->task->title . ' - ' . yii::t('task', 'rollback'),
            'commit_id' => $this->task->commit_id,
        ];
        if ($rollbackTask->save()) {
            $url = $conf->audit == Project::AUDIT_YES
                ? '/task/'
                : '/walle/deploy?taskId=' . $rollbackTask->id;
            $this->renderJson([
                'url' => $url,
            ]);
        } else {
            $this->renderJson([], -1, yii::t('task', 'create a rollback job failed'));
        }
    }

    /**
     * 任务审核
     *
     * @param $id
     * @param $operation
     */
    public function actionTaskOperation($id, $operation) {
        $task = Task::findOne($id);
        if (!$task) {
            static::renderJson([], -1, yii::t('task', 'unknown deployment bill'));
        }
        // 是否为该项目的审核管理员（超级管理员可以不用审核，如果想审核就得设置为审核管理员，要不只能维护配置）
        if (!Group::isAuditAdmin($this->uid, $task->project_id)) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }

        $task->status = $operation ? Task::STATUS_PASS : Task::STATUS_REFUSE;
        $task->save();
        static::renderJson(['status' => \Yii::t('w', 'task_status_' . $task->status)]);
    }

}
