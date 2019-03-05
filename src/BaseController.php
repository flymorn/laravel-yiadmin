<?php

namespace Encore\Admin;

use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Show;
use DB;
use Illuminate\Support\Collection;
use Encore\Admin\ExcelExpoter;

class BaseController extends Controller
{
    use HasResourceActions;

    // 属性定义
    protected $title;// 标题
    protected $model;// 对应的模型，如 \App\Models\Post::class;
    protected $rename_fields = [];// 需要调整的字段说明 ['filepaths' => '图片']
    protected $form_display_fields = [];// 表单页仅显示(不可编辑)的字段，['id','created_at']
    protected $sort_fields = [];// 需要调整排序的字段(前后位置) ['title' => 1.1]
    
    // 私有属性，仅限内部使用
    private $_form_edit_fields = [];// 表单可编辑的字段，内部使用
    private $_form_show_fields = [];// 表单仅显示的字段，内部使用
    private $_table_fields = [];// 所有字段，内部使用


    /**
     * 构造方法 / Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->_table_fields = $this->get_fields();// 获取所有字段
        foreach ($this->_table_fields as $item) {
            if(in_array($item['Field'], $this->form_display_fields)){
                $this->_form_show_fields[] = $item;
            }else{
                $this->_form_edit_fields[] = $item;
            }
        }
    }

    /**
     * 列表页 / Index interface.
     *
     * @param Content $content
     * @return Content
     */
    public function index(Content $content)
    {
        return $content
            ->header($this->title.'列表 / Index')
            ->description(' ')
            ->body($this->grid());
    }

    /**
     * 详情页 / Show interface.
     *
     * @param mixed $id
     * @param Content $content
     * @return Content
     */
    public function show($id, Content $content)
    {
        return $content
            ->header($this->title.'详情 / Detail')
            ->description(' ')
            ->body($this->detail($id));
    }

    /**
     * 编辑页 / Edit interface.
     *
     * @param mixed $id
     * @param Content $content
     * @return Content
     */
    public function edit($id, Content $content)
    {
        return $content
            ->header($this->title.'编辑 / Edit')
            ->description(' ')
            ->body($this->form()->edit($id));
    }

    /**
     * 创建页 / Create interface.
     *
     * @param Content $content
     * @return Content
     */
    public function create(Content $content)
    {
        return $content
            ->header($this->title.'创建 / Create')
            ->description(' ')
            ->body($this->form());
    }

    /**
     * 表格构造器 / Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new $this->model);
        // 循环所有字段
        foreach ($this->_table_fields as $field) {
            if($field['Field'] == 'id') $grid->id('ID')->sortable();
            else $grid->column($field['Field'], $field['Comment']);
        }
        // 使用Laravel Excel导出
        $grid->exporter(new ExcelExpoter());
        // 回调函数
        call_user_func([$this, 'grid_callback'], $grid);
        // 删除重复定义的字段(保留新的设置)
        $arr_tmp = [];
        $collection = $grid->columns();
        $reversed = $collection->reverse();// 倒转顺序
        foreach ($reversed as $k => $c) {
            if(in_array($c->getName(), $arr_tmp)){
                $collection->forget($k);// 改变原有集合
            }else{
                $arr_tmp[] = $c->getName();
            }
        }
        // 还原字段原始排序 start ------
        $collection = $grid->columns();
        $newCollection = new Collection();
        // 循环查找排序
        foreach ($this->_table_fields as $item) {
            $field = $item['Field'];
            foreach ($collection as $c) {
                if($c->getName() == $field){
                    $newCollection[] = $c;
                }
            }
        }
        // 再补充上虚拟字段
        foreach ($collection as $key => $value) {
            $find = false;
            foreach ($newCollection as $nkey => $nvalue) {
                if($value->getName() == $nvalue->getName()){
                    $find = true; break;
                }
            }
            if(!$find) $newCollection[] = $value;
        }
        // 实现自定义排序
        $arr = [];
        $i = 0;
        foreach ($newCollection as $item) {
            $sort_id = $i++;// 默认顺序，递进1
            // 判断是否需要调整排序
            if(array_key_exists($item->getName(), $this->sort_fields)) $sort_id = $this->sort_fields[$item->getName()];
            $arr[] = ['obj' => $item, 'sort_id' => $sort_id];
        }
        $collectionTmp = collect($arr);
        $sortedCollection = $collectionTmp->sortBy('sort_id');// 排序，保留原数组键
        $sortedCollection = $sortedCollection->values()->all();// 使用 values 方法将键重置为连续编号的索引
        // 组装排序好的集合
        $myCollection = new Collection();
        foreach ($sortedCollection as $item) {
            $myCollection[] = $item['obj'];
        }
        // 实现自定义排序 - END
        // transform 方法会修改集合本身
        $i = 0;
        $collection->transform(function ($item, $key) use ($myCollection, &$i){
            return $myCollection[$i++];
        });
        // 还原字段原始排序 end ------);

        return $grid;
    }

    /**
     * 详情构造器 / Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show($this->model::findOrFail($id));
        // 循环字段
        foreach ($this->_table_fields as $item) {
            $show->{$item['Field']}($item['Comment']);
        }
        // 回调函数
        call_user_func([$this, 'detail_callback'], $show);
        // 还原字段原始排序 start ------
        // ****************************************
        // 注意：源码 src/Show.php 的属性 $fields 模式由 protected 改为 public
        // ****************************************
        $collection = $show->fields;
        $newCollection = new Collection();
        foreach ($this->_table_fields as $item) {
            $field = $item['Field'];
            foreach ($collection as $c) {
                if($c->getName() == $field){
                    $newCollection[] = $c;
                }
            }
        }
        $show->fields = $newCollection;
        // 还原字段原始排序 end ------

        return $show;
    }

    /**
     * 表单构造器 / Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new $this->model);

        // 可编辑字段
        foreach ($this->_form_edit_fields as $item) {
            $form->text($item['Field'], $item['Comment']);
        }
        // 仅显示字段
        foreach ($this->_form_show_fields as $item) {
            $form->display($item['Field'], $item['Comment'])->with(function ($value) {
                return "$value";
            });
        }
        // 回调函数
        call_user_func([$this, 'form_callback'], $form);
        // 删除重复定义的字段(保留新的设置)
        $arr_tmp = [];
        $collection = $form->builder()->fields();
        $reversed = $collection->reverse();// 倒转顺序
        foreach ($reversed as $k => $c) {
            if(in_array($c->column(), $arr_tmp)){
                $collection->forget($k);// 改变原有集合
            }else{
                $arr_tmp[] = $c->column();
            }
        }
        // 还原字段原始排序 start ------
        $collection = $form->builder()->fields();
        $newCollection = new Collection();
        $temp=array();
        // 循环查找
        foreach ($this->_table_fields as $item) {
            $field = $item['Field'];
            foreach ($collection as $c) {

                if($c->column() == $field){
                    $temp[]=$c->column();
                    $newCollection[] = $c;
                }
                if(is_array($c->column()) && in_array($field,$c->column()) && !in_array($c->column(),$temp)){
                    $temp[]=$c->column();
                    $newCollection[] = $c;
                }
            }
        }
        // transform 方法会修改集合本身
        $i = 0;
        $collection->transform(function ($item, $key) use ($newCollection, &$i){
            return $newCollection[$i++];
        });
        // 还原字段原始排序 end ------

        return $form;
    }

    /**
     * -----------------------------
     * 以下为自定义方法
     * -----------------------------
     */

    /**
     * 返回模型表所有字段
     */
    protected function get_fields()
    {
        $myarr = [];
        // 获取所有字段
        $table = (new $this->model)->getTable();
        //$arr = config("tablecomments.{$table}");// 需要提前生成数据表结构缓存
        $result = \DB::select("SHOW FULL COLUMNS FROM `$table`");// 获取表结构
        foreach ($result as $item) {
            $arr[] = ["Field" => $item->Field, "Comment" => $item->Comment ? $item->Comment : $item->Field];
        }
        // 循环
        foreach ($arr as $item) {
            $Field = $item['Field'];
            $Comment = $item['Comment'];
            // 判断是否需要调整备注名称
            if(array_key_exists($Field, $this->rename_fields)) $Comment = $this->rename_fields[$Field];
            $myarr[] = [
                'Field' => $Field,
                'Comment' => $Comment,
            ];
        }
        return $myarr;
    }


    /**
     * 列表页表格构造器回调方法 - 在继承类中覆盖
     *
     * @return Grid
     */
    protected function grid_callback(Grid $grid)
    {
    }

    /**
     * 表格构造器 - 移除不需要的字段
     *
     * @return Form
     */
    public function grid_remove_fields(Grid $grid, $array)
    {
        // 移除不需要的字段
        // $array = ['status', 'password', 'proportion'];
        $collection = $grid->columns();
        foreach ($collection as $k => $c) {
            if(in_array($c->getName(), $array)) {
                // forget 不会返回修改过后的新集合；它会直接修改原来的集合
                $collection->forget($k);
            }
        }
    }


    /**
     * 详情页自定义回调方法 - 在继承类中覆盖
     *
     * @param Show $show
     * @return Content
     */
    public function detail_callback(Show $show)
    {
    }

    /**
     * Detail 详情页 - 移除不需要的字段
     *
     * 注意：源码 src/Show.php 的属性 $fields 模式由 protected 改为 public
     */
    public function detail_remove_fields(Show $show, $array)
    {
        // $array = ['status', 'password', 'proportion'];
        $collection = $show->fields;
        foreach ($collection as $k => $c) {
            if(in_array($c->getName(), $array)) {
                // forget 不会返回修改过后的新集合；它会直接修改原来的集合
                $collection->forget($k);
            }
        }
    }


    /**
     * 表单构造器回调方法 - 在继承类中覆盖
     *
     * @return Form
     */
    protected function form_callback(Form $form)
    {
    }

    /**
     * 表单构造器 - 移除不需要的字段
     *
     * @return Form
     */
    public function form_remove_fields(Form $form, $array)
    {
        // 移除不需要的字段
        // $array = ['status', 'password', 'proportion'];
        $collection = $form->builder()->fields();
        foreach ($collection as $k => $c) {
            if(in_array($c->column(), $array)) {
                // forget 不会返回修改过后的新集合；它会直接修改原来的集合
                $collection->forget($k);
            }
        }
    }


}
