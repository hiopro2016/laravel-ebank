<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\BasicRequest;
use App\Models\FundOrder;
use App\Models\FundPurseType;
use App\Models\FundTransfer;
use Illuminate\Support\Facades\DB;

class IndexController extends CommonController
{
	public function index(BasicRequest $request){
		return view('admin.index');
	}
	
	/**
	 * 页面菜单/用户信息初始化
	 * @param BasicRequest $request
	 * @return array
	 */
	public function init(BasicRequest $request){
		$data['user'] = admin_user();
		$data['menu'] = init_menu();
		return json_success('OK',$data);
	}
	
	/**
	 * 主页，数据统计等
	 * @param BasicRequest $request
	 * @return string
	 */
	public function welcome(BasicRequest $request){
		$fundOrder = new FundOrder();
		$payments = $fundOrder->payments;
		$payments['total'] = '总额';
		// 15天
		$into = ['date'=>[],'amount'=>[]];
		for($i=14;$i>=0;$i--){
			$date = date('Y-m-d',strtotime("-$i days"));
			$into['date'][] = $date;
			$into['amount']['total'][$date] = '';
			foreach($payments as $payment => $payment_name){
				$into['amount'][$payment][$date] = '';
			}
		}
		
		$latest = array_shift(array_slice($into['date'],0,1));
		
		FundOrder::rightJoin('fund_order_payment as payment','payment.order_id','=','fund_order.id')
			->select(DB::raw('date_format(fund_order.created_at,\'%Y-%m-%d\') as date,payment.type,sum(payment.amount) amount'))
			->where(['fund_order.status'=>1,'fund_order.pay_status'=>1])->where('fund_order.created_at','>=',$latest.' 00:00:00')
			->groupBy('payment.type')
			->groupBy('date')
			->get()->each(function($v,$k) use (&$into){
				$into['amount'][$v->type][$v->date] = $v->amount;
				$into['amount']['total'][$v->date] += $v->amount;
			});
		return json_success('OK',['into'=>$into,'out'=>[],'payments'=>$payments]);
	}
	
	/**
	 * 用户身份收入、支出统计
	 * @param BasicRequest $request
	 * @return array
	 */
	public function user_transfer(BasicRequest $request){
		$out = $into = [];
		$series = [];
		$purse_types_all = FundPurseType::active()->get(['id','name','alias']);
		
		$purse_types = collect([
			'total'		=> '总额',
			'-total'	=> '总额(负)',
		]);
		$purse_types_all->each(function($v) use (&$series,&$purse_types){
			$series[$v->id] = $v->alias;
			$purse_types[$v->alias] = $v->name.'(收入)';
			$purse_types['-'.$v->alias] = $v->name.'(支出)';
		});
		
		$dates = [];
		// 数据初始化为0，图表数据占位
		for($i=14;$i>=0;$i--){
			$date = date('Y-m-d',strtotime("-$i days"));
			$dates[] = $date;
//			$out['-total'][$date] = '';
//			$into['total'][$date] = '';
			$purse_types_all->each(function($v) use (&$out,&$into,$date){
				$out['-'.$v->alias][$date] = '';
				$into[$v->alias][$date] = '';
			});
		}
		$latest = array_shift(array_slice($dates,0,1));
		
		$model_out = FundTransfer::select(DB::raw('out_purse_type_id,into_purse_type_id,date_format(created_at,\'%Y-%m-%d\') as date,sum(amount) as amount'))
			->where('created_at','>=',$latest.' 00:00:00')
			->where(['status'=>1])
			->groupBy('date');
		$model_into = clone $model_out;
		
		$model_out->where('out_user_id','!=',0)->where(['out_user_type_id'=>3])->groupBy('out_purse_type_id')->get()->each(function($v) use (&$out,$series){
			$out['-'.$series[$v->out_purse_type_id]][$v->date] = -$v->amount;
//			$out['-total'][$v->date] += -$v->amount;
		});
		$model_into->where('into_user_id','!=',0)->where(['into_user_type_id'=>3])->groupBy('into_purse_type_id')->get()->each(function($v) use (&$into,$series){
			$into[$series[$v->into_purse_type_id]][$v->date] = $v->amount;
//			$into['total'][$v->date] += $v->amount;
		});
		$amounts = array_merge($into,$out);
		return json_success('OK',compact('dates','purse_types','out','into','amounts'));
	}
	
	/**
	 * 服务器版本信息
	 * @param BasicRequest $request
	 * @return array
	 */
	public function sysinfo(BasicRequest $request){
		$data = [
			'服务器系统'		=> php_uname(),
			'运行模式'		=> php_sapi_name(),
			'运行用户名'		=> Get_Current_User(),
			'PHP版本'		=> PHP_VERSION,
			'运行时最大内存'	=> ini_get('memory_limit'),
			'时区'			=> config('app.timezone'),
			'允许上传大小'	=> ini_get('upload_max_filesize'),
			'数据库版本'		=> array_shift(DB::select('select version() as version'))->version,
			'服务器IP'		=> GetHostByName($_SERVER['SERVER_NAME']),
			'客户端IP'		=> $_SERVER['REMOTE_ADDR'],
		];
		return json_success('OK',$data);
	}
}
