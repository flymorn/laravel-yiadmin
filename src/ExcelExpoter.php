<?php

namespace Encore\Admin;

use Encore\Admin\Grid\Exporters\AbstractExporter;
use Maatwebsite\Excel\Facades\Excel;

class ExcelExpoter extends AbstractExporter
{
    public function export()
    {
        Excel::create('Filename', function($excel) {

            $excel->sheet('Sheetname', function($sheet) {
                // 表头，只输出1次
                $header = [];
                $data = $this->getData();
                foreach ($data as $key => $value) {
                    foreach ($value as $key2 => $value2) {
                         // 去除关联关系的字段，导出会报错
                        if(!is_array($value2)) $header[] = $key2;
                    }
                    break;
                }
                $sheet->rows([$header]);// 表头，只输出1次


                // 这段逻辑是从表格数据中取出需要导出的字段
                $rows = collect($this->getData())->map(function ($item){
                    //return array_only($item, ['id', 'title', 'content']);
                    // 去除关联关系的字段，导出会报错
                    foreach ($item as $key => $value) {
                        if(is_array($value)) unset($item[$key]);
                    }
                    return $item;
                });
                //$rows = collect($this->getData());

                $sheet->rows($rows);

            });

        })->export('xls');
    }
}
