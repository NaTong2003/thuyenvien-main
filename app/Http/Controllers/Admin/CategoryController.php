<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Question;
use App\Models\Test;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    /**
     * Hiển thị danh sách danh mục
     */
    public function index()
    {
        $categories = Category::withCount(['questions', 'tests'])->get();
//Lấy tất cả danh mục kèm theo số lượng câu hỏi và bài kiểm tra của mỗi danh mục.
        return view('admin.categories.index', compact('categories'));
    }
    
    /**
     * Hiển thị form tạo danh mục mới
     */
    public function create()
    {
        return view('admin.categories.create');
    }
    
    /**
     * Lưu danh mục mới
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
        ]);
        
        try {
            $category = Category::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                //Dòng này chuyển tên thành dạng chữ thường, không dấu, dùng cho URL dễ đọc và tìm kiếm hơn.
                'description' => $request->description,
                'color' => $request->color ?? '#4e73df',
                'icon' => $request->icon ?? 'fas fa-folder',
            ]);
            
            return redirect()->route('admin.categories.index')
                ->with('success', 'Đã tạo danh mục mới thành công!');
        } catch (\Exception $e) {
            Log::error('Lỗi tạo danh mục: ' . $e->getMessage());
            return back()->with('error', 'Đã xảy ra lỗi khi tạo danh mục: ' . $e->getMessage())->withInput();
        }
    }
    //Nếu tạo danh mục bị lỗi, ghi lỗi vào file log, quay lại trang trước và hiển thị thông báo lỗi đồng thời giữ lại dữ liệu người dùng đã nhập.
    
    /**
     * Hiển thị thông tin chi tiết danh mục
     */
    public function show($id)
    {
        $category = Category::with(['questions', 'tests'])->findOrFail($id);
       // Dòng code này tìm danh mục theo ID rồi lấy luôn các câu hỏi và bài kiểm tra thuộc về danh mục đó.
        
        // Lấy các thống kê
        $questionCount = $category->questions->count();
        $testCount = $category->tests->count();
        
        // Thống kê về câu hỏi theo độ khó
        $questionsByDifficulty = $category->questions
        //Dòng này lấy tất cả các câu hỏi liên quan đến danh mục $category và gán vào biến $questionsByDifficulty.
            ->groupBy('difficulty')
            ->map(function ($items, $key) {
                return [
                    'label' => $key ?: 'Chưa phân loại',
                    'count' => $items->count()
                ];
            })->values();
//Hàm map sẽ lặp qua từng phần tử trong tập hợp dữ liệu. Mỗi phần tử có 2 giá trị:

//$items: Là giá trị (có thể là một tập hợp con).

//$key: Là khóa của phần tử (có thể là tên hoặc chỉ số).
        return view('admin.categories.show', compact('category', 'questionCount', 'testCount', 'questionsByDifficulty'));
    }
    
    /**
     * Hiển thị form chỉnh sửa danh mục
     */
    public function edit($id)
    {
        $category = Category::findOrFail($id);//Dòng này tìm danh mục theo $id, nếu không tìm thấy thì báo lỗi
        return view('admin.categories.edit', compact('category'));
    }
    
    /**
     * Cập nhật danh mục
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
        ]);
        
        try {
            $category->update([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
    //Dòng này tạo chuỗi slug (URL thân thiện) từ tên người dùng nhập, chuyển thành chữ thường, không dấu, cách nhau bằng dấu gạch ngang.
                'description' => $request->description,
                'color' => $request->color,
                'icon' => $request->icon,
            ]);
            
            return redirect()->route('admin.categories.index')
                ->with('success', 'Đã cập nhật danh mục thành công!');
        } catch (\Exception $e) {
            Log::error('Lỗi cập nhật danh mục: ' . $e->getMessage());
            return back()->with('error', 'Đã xảy ra lỗi khi cập nhật danh mục: ' . $e->getMessage())->withInput();
        }
    }
    //Nếu xảy ra lỗi khi cập nhật danh mục, đoạn code sẽ ghi lỗi vào file log, 
    // quay lại trang trước,
    //  hiện thông báo lỗi cho người dùng và giữ lại dữ liệu đã nhập để không phải nhập lại.
    /**
     * Xóa danh mục
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            
            // Kiểm tra xem danh mục có đang được sử dụng không
            $questionCount = $category->questions()->count();
            $testCount = $category->tests()->count();
            //Đếm số câu hỏi và số bài kiểm tra thuộc về danh mục đó.
            if ($questionCount > 0 || $testCount > 0) {
                //Nếu số câu hỏi hoặc số bài kiểm tra trong danh mục lớn hơn 0 thì… (thực hiện đoạn lệnh bên trong).
                return redirect()->route('admin.categories.index')
                    ->with('error', 'Không thể xóa danh mục này vì đang được sử dụng bởi ' . 
                           $questionCount . ' câu hỏi và ' . $testCount . ' bài kiểm tra.');
            }
    //Chuyển hướng về trang danh sách danh mục 
    //hiển thị thông báo lỗi rằng danh mục không thể xóa vì đang có questionCount câu hỏi và testCount bài kiểm tra liên quan.
            $category->delete();
            
            return redirect()->route('admin.categories.index')
                ->with('success', 'Đã xóa danh mục thành công!');
        } catch (\Exception $e) {
            Log::error('Lỗi xóa danh mục: ' . $e->getMessage());
            return back()->with('error', 'Đã xảy ra lỗi khi xóa danh mục: ' . $e->getMessage());
        }
    }
} 