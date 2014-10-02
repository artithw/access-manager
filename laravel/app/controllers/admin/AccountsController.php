<?php

Class AccountsController extends AdminBaseController {

	const HOME = 'subscriber.index';

	public function getActive()
	{
		$q = DB::table('radacct as a')
				->select('u.uname','u.fname','u.lname','u.contact',
						// 'r.expiration', 
						'a.acctstarttime')
				->join('user_accounts as u','u.uname','=','a.username')
				// ->join('user_recharges as r','u.id','=','r.user_id')
				// ->join('prepaid_vouchers as v', 'r.voucher_id','=','v.id')
				->where('a.acctstoptime', NULL);

		$alphabet = Input::get('alphabet', NULL);
		if( !is_null($alphabet) ) {
			$q->where('u.uname','LIKE',"$alphabet%");
		}
		return View::make('admin.accounts.dashboard')
					->with('active', $q->paginate(10));
	}

	public function getIndex()
	{
		$accounts = Subscriber::with('Recharge')
								->where('is_admin',0)->paginate(10);
								
		return View::make('admin.accounts.index')
							->with('accounts',$accounts);
	}

	public function getAdd()
	{
		return View::make('admin.accounts.add-edit');
	}

	public function postAdd()
	{
		try {
			$input = Input::all();
		
			$rules = Config::get('validations.accounts');
			$rules['uname'][] = 'unique:user_accounts';
			
			$v = Validator::make($input, $rules);
			$v->setAttributeNames( Config::get('attributes.accounts') );
			if( $v->fails() )
				return Redirect::back()
								->withInput()
								->withErrors($v);

			$input['clear_pword'] = $input['pword'];
			$input['pword'] = Hash::make($input['pword']);
			$input['plan_type'] = PREPAID_PLAN;
			
			$account = Subscriber::create($input);
			// if( $account->plan_type == FREE_PLAN )
			// 	Subscriber::updateFreePlan($account->id);

			$this->notifySuccess("New Subscriber added successfully: <b>{$input['uname']}</b>");
		}
		catch(Exception $e) {
			$this->notifyError($e->getMessage());
			return Redirect::route(self::HOME);
		}
		return Redirect::route(self::HOME);
	}

	public function getEdit($id)
	{
		try{
			$account = Subscriber::findOrFail($id);
			return View::make('admin.accounts.add-edit')
									->with('account',$account);
		}
		catch(Illuminate\Database\Eloquent\ModelNotFoundException $e) {
			App::abort(404);
		}
	}

	public function postEdit()
	{
		$input = Input::all();
		$rules = Config::get('validations.accounts');
		$rules['uname'][] = 'unique:user_accounts,uname,' . $input['id'];

		$v = Validator::make($input, $rules);
		$v->setAttributeNames( Config::get('attributes.accounts') );
		if( $v->fails() )
			return Redirect::back()
							->withInput()
							->withErrors($v);
		try{
			if( ! Input::has('id')) throw new Exception("Required parameter missing: ID");

			$account = Subscriber::find($input['id']);
			if( ! $account )		throw new Exception("No such user with id:{$input['id']}");
			$account->fill($input);
			if( ! $account->save() )	throw new Exception("Failed to update account.");
			
			// switch($account->plan_type) {
			// 	case FREE_PLAN :
			// 	Subscriber::updateFreePlan($account->id);
			// 	break;
			// 	case PREPAID_PLAN :
			// 	Subscriber::updatePrepaidPlan($account->id);
			// 	break;
			// }
			$this->notifySuccess("Account successfully updated.");
		}
		catch(Exception $e) {
			$this->notifyError( $e->getMessage() );
			return Redirect::route(self::HOME);
		}
		return Redirect::route(self::HOME);
	}

	public function postDelete($id)
	{
		$this->notifyError("Operation Not Permitted.");
		return Redirect::back();
		//////////////////////////////////////////////////////
		try{
			DB::transaction(function() use($id) {
				if( ! Subscriber::destroy($id) ||
					( Recharge::where('user_id',$id)->count() && ! Recharge::where('user_id',$id)->delete() )
				) throw new Exception("Account could not be deleted, please try again.");
			});
			$this->notifySuccess("Account Successfully deleted.");
			return Redirect::route(self::HOME);
		}
		catch(Exception $e) {
			$this->notifyError($e->getMessage());
			return Redirect::route(self::HOME);
		}
	}

	public function getProfile($id)
	{
		try{
			$profile = Subscriber::findOrFail($id);
			$rc_history = $profile->rechargeHistory()->take(5)->get();
			$sess_history = $profile->sessionHistory()->paginate(10);

			return View::make('admin.accounts.profile')
						->with('profile',$profile)
						->with('rc_history', $rc_history)
						->with('sess_history', $sess_history);
		}
		catch(Illuminate\Database\Eloquent\ModelNotFoundException $e) {
			App::abort(404);
		}
	}

	public function getAssignPlan($user_id)
	{
		$profile = Subscriber::findOrFail($user_id);
		$plans = Plan::lists('name','id');
		return View::make("admin.accounts.assign-plan")
					->with('profile', $profile)
					->with('plans', $plans);
	}

	public function postAssignPlan()
	{
		try{
			$user_id = Input::get('user_id', 0);
			$plan_id = Input::get('plan_id', 0);
			APActivePlan::AssignPlan($user_id, $plan_id);
			$this->notifySuccess("Plan Assigned.");
		}
		catch(Exception $e) {
			$this->notifyError($e->getMessage());
			return Redirect::route("subscriber.services",$user_id);
		}
		return Redirect::route("subscriber.services",$user_id);

	}

	public function getActiveServices($user_id)
	{
		$profile = Subscriber::findOrFail($user_id);
		$plan = Subscriber::getActiveServices($profile);
		$framedIP = SubnetIP::where('user_id',$user_id)->first();
		$framedRoute = UserRoute::where('user_id',$user_id)->first();
		return View::make("admin.accounts.services")
					->with('profile', $profile)
					->with('plan', $plan)
					->with('framedIP',$framedIP)
					->with('framedRoute', $framedRoute);
	}

	public function postResetPassword()
	{
		$pword = Input::get('npword');
		$id = Input::get('id');

		$affectedRows =	Subscriber::where('id', $id)
					->update([
							'pword'		=>	Hash::make($pword),
						'clear_pword'	=>	$pword,
						]);
		if($affectedRows) {
			$this->notifySuccess("Password Changed.");
		} else {
			$this->notifyError("Failed to change password.");
		}
		return Redirect::back();
	}

	public function getChangeServiceType($user_id)
	{
		$profile = Subscriber::findOrFail($user_id);
		return View::make("admin.accounts.change-service-type")
					->with('profile', $profile);
	}

	public function postChangeServiceType()
	{
		try {
			$user_id = Input::get('user_id');
			DB::transaction(function()use($user_id){
				$user = Subscriber::findOrFail($user_id);
				$user->plan_type = Input::get('plan_type');
				if( ! $user->save() )	throw new Exception('Failed to change service type.');
				if( $user->plan_type == ADVANCEPAID_PLAN ) {
					$billing = BillingCycle::firstOrNew(['user_id'=>$user_id]);
					$billing->fill(Input::all());
					if( ! $billing->save() )	throw new Exception("Failed to save billing cycle details.");
				}
				if( $user->plan_type == FREE_PLAN ) {
					Subscriber::updateFreePlan($user_id);
				}
			});

			$this->notifySuccess("Service Type Updated.");
		}
		catch(Exception $e)
		{
			$this->notifyError($e->getMessage());
			return Redirect::route('subscriber.profile', $user_id);
		}
		return Redirect::route('subscriber.profile', $user_id);
	}

	public function postRefill()
	{
		
	}
	
}