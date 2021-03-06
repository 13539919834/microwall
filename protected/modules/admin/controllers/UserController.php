<?php
class UserController extends AdminController
{
	public function actionIndex()
	{
		$users = User::model()->findAll();
		
		$this->_menu = 'user';
		$this->pageTitle = Yii::t('AdminModule.user', 'Users');
		$this->render('index', array('users' => $users));
	}
	
	public function actionNew()
	{
		$this->_menu = 'new';
		$this->pageTitle = Yii::t('AdminModule.user', 'Add User');
		
		if (!Yii::app()->user->checkAccess('openCreateUser'))
			$this->_permissionDenied();
		
		$model = new User();
		
		if (isset($_POST['User'])) {
			unset($_POST['User']['avatar']);
			$model->attributes = $_POST['User'];
			
			if (!Yii::app()->user->checkAccess('createUser', array('role' => $model->role)))
				$this->_permissionDenied();
			
			try {
				$avatar = CUploadedFile::getInstance($model, 'avatar');
				
				$hasAvatar = false;
				if ($avatar) {
					$model->avatar = $avatar->name;
					
					if ($model->validate()) {
						$model->avatar = User::processAvatar($avatar, $model);
						$hasAvatar = true;
					}
				}
				
				if ($model->save()) {
					if ($hasAvatar) {
						$tempPath = Yii::getPathOfAlias('webroot.media.temp') . DIRECTORY_SEPARATOR;
						$avatarPath = Yii::getPathOfAlias('webroot.media.avatar') . DIRECTORY_SEPARATOR;
						
						rename($tempPath . $model->avatar, $avatarPath . $model->avatar);
					}
					
					Yii::app()->authManager->assign($model->role, $model->id);
					Yii::app()->user->setFlash('success', Yii::t('AdminModule.user', 'Adding a user successfully!'));
					$this->redirect($this->createUrl('index'));
				} else
					throw new Exception(Yii::t('AdminModule.user', 'Add user failed!'));
			} catch (Exception $e) {
				Yii::app()->user->setFlash('error', $e->getMessage());
			}
		}
		
		$this->render('user', array('model' => $model, 'roles' => User::getRoles()));
	}
	
	public function actionEdit($id)
	{
		if ($id === Yii::app()->user->id) {
			$this->_menu = 'profile';
			$this->pageTitle = Yii::t('AdminModule.user', 'Profile');
		} else {
			$this->_menu = 'user';
			$this->pageTitle = Yii::t('AdminModule.user', 'Edit User');
		}
		
		$model = User::model()->findByPk($id);
		
		if (!$model)
			throw new CHttpException('404', 'This id was not found.');
		
		$oldRole = $model->role = key(Yii::app()->authManager->getAuthAssignments($model->id));
		if (!Yii::app()->user->checkAccess('editUser', array('userId' => $model->id, 'role' => $oldRole)))
			$this->_permissionDenied();
		
		$oldAvatar = $model->avatar;
		$model->password = null;
		
		if (isset($_POST['User'])) {
			unset($_POST['User']['avatar']);
			unset($_POST['User']['name']);
			unset($_POST['User']['role']);
			
			$model->attributes = $_POST['User'];
			
			try {
				$avatar = CUploadedFile::getInstance($model, 'avatar');
				
				$hasAvatar = false;
				if ($avatar) {
					$model->avatar = $avatar->name;
					
					if ($model->validate()) {
						$model->avatar = User::processAvatar($avatar, $model);
						$hasAvatar = true;
					}
				}
				
				if ($model->save()) {
					if ($hasAvatar) {
						$tempPath = Yii::getPathOfAlias('webroot.media.temp') . DIRECTORY_SEPARATOR;
						$avatarPath = Yii::getPathOfAlias('webroot.media.avatar') . DIRECTORY_SEPARATOR;
						
						rename($tempPath . $model->avatar, $avatarPath . $model->avatar);
						if (substr($model->avatar, 0, 8) !== 'default/' && is_file($avatarPath . $oldAvatar))
							unlink($avatarPath . $oldAvatar);
					}
					Yii::app()->user->setFlash('success', Yii::t('AdminModule.user', 'User information changed successfully!'));
					$this->refresh();
				} else {
					$model->avatar = $oldAvatar;
					throw new Exception(Yii::t('AdminModule.user', 'Failed to modify user information!'));
				}
			} catch (Exception $e) {
				Yii::app()->user->setFlash('error', $e->getMessage());
			}
		}

		$this->render('user', array('model' => $model, 'roles' => User::getRoles(false)));
	}
	
	public function actionRemove($id)
	{
		$model = User::model()->findByPk($id);
		
		if ($model) {
			$role = key(Yii::app()->authManager->getAuthAssignments($model->id));
			
			if (!Yii::app()->user->checkAccess('removeUser', array('userId' => $model->id, 'role' => $role))) {
				if (Yii::app()->request->isAjaxRequest) {
					echo json_encode(array('error' => '401'));
					Yii::app()->end();
				} else {
					$this->_menu = 'user';
					$this->pageTitle = Yii::t('AdminModule.user', 'Delete User');
					$this->_permissionDenied();
				}
			}
			
			if (Post::model()->exists('author=:author', array(':author' => $id))) {
				if (Yii::app()->request->isAjaxRequest) {
					echo json_encode(array('error' => 'redirect', 'url' => $this->createUrl('removeChild', array('id' => $id))));
					Yii::app()->end();
				} else
					$this->redirect($this->createUrl('removeChild', array('id' => $id)));
			} else {
				$avatarPath = Yii::getPathOfAlias('webroot.media.avatar') . DIRECTORY_SEPARATOR;
				Yii::app()->authManager->revoke($role, $model->id);
				if (!empty($model->avatar) && substr($model->avatar, 0, 8) !== 'default/' && is_file($avatarPath . $model->avatar))
					unlink($avatarPath . $model->avatar);
				if (!$model->delete()) {
					if (Yii::app()->request->isAjaxRequest) {
						echo json_encode(array('error' => '417'));
						Yii::app()->end();
					} else {
						Yii::app()->user->setFlash('error', Yii::t('AdminModule.user', 'User delete failed!'));
						$this->redirect(array('index'));
					}
				}
			}
		}
		
		if (Yii::app()->request->isAjaxRequest)
			echo json_encode(array('error' => '200'));
		else {
			Yii::app()->user->setFlash('success', Yii::t('AdminModule.user', 'User deleted successfully!'));
			$this->redirect(array('index'));
		}
	}
	
	public function actionRemoveChild($id)
	{
		$this->_menu = 'user';
		$this->pageTitle = Yii::t('AdminModule.global', 'Delete Warning');
		
		$model = User::model()->findByPk($id);
		
		if ($model) {
			$role = key(Yii::app()->authManager->getAuthAssignments($model->id));
				
			if (!Yii::app()->user->checkAccess('removeUser', array('userId' => $model->id, 'role' => $role)))
				$this->_permissionDenied();
				
			if (Post::model()->exists('author=:author', array(':author' => $id))) {
				$userRemoveForm = new UserRemoveForm();
				$userRemoveForm->id = $id;
				
				if (isset($_POST['UserRemoveForm'])) {
						
					$userRemoveForm->attributes = $_POST['UserRemoveForm'];
					if ($userRemoveForm->validate()) {
						$avatarPath = Yii::getPathOfAlias('webroot.media.avatar') . DIRECTORY_SEPARATOR;
						Yii::app()->authManager->revoke($role, $model->id);
						if (!empty($model->avatar) && substr($model->avatar, 0, 8) !== 'default/' && is_file($avatarPath . $model->avatar))
							unlink($avatarPath . $model->avatar);
						if ($model->delete())
							Yii::app()->user->setFlash('success', Yii::t('AdminModule.user', 'User deleted successfully!'));
						else
							Yii::app()->user->setFlash('error', Yii::t('AdminModule.user', 'User delete failed!'));
						$this->redirect($this->createUrl('index'));
					}
					if (in_array('401', $userRemoveForm->getErrors('type')))
						$this->_permissionDenied();
				}
				
				$this->render('user-remove', array(
						'user' => $model,
						'model' => $userRemoveForm
				));
				Yii::app()->end();
			}
		}
		
		$this->redirect($this->createUrl('index'));
	}
}