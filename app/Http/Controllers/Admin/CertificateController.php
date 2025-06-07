<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Test;
use App\Models\TestAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class CertificateController extends Controller
{
    /**
     * Hiển thị danh sách chứng chỉ
     */
    public function index(Request $request)
    {
        $query = Certificate::with(['user', 'test', 'issuer']);
        //Lấy dữ liệu chứng chỉ kèm theo thông tin người dùng, bài kiểm tra và người cấp chứng chỉ.
        // Lọc theo tìm kiếm
        if ($request->has('search') && !empty($request->search)) {
//kiểm tra xem người dùng có gửi dữ liệu tìm kiếm (search) lên hay không, và nếu có thì dữ liệu đó không được để trống (không phải chuỗi rỗng).
            $search = $request->search;
            $query->where(function($q) use ($search) { 
// bắt đầu tạo một nhóm điều kiện lọc trong truy vấn, dùng biến $search để áp dụng các điều kiện tìm kiếm bên trong nhóm đó.
                $q->where('certificate_number', 'like', '%' . $search . '%')
                  ->orWhere('title', 'like', '%' . $search . '%')
                  ->orWhereHas('user', function($userQuery) use ($search){
// giúp tìm chứng chỉ dựa vào tên người dùng liên quan chứa từ khóa bạn đang tìm.
                      $userQuery->where('name', 'like', '%' . $search . '%');// tìm người dùng mà trường name chứa chuỗi $search ở bất cứ vị trí nào (ở đầu, giữa hoặc cuối).
                  });
            });
        }
        // tìm chứng chỉ theo số, tiêu đề hoặc tên người dùng
        
        // Lọc theo trạng thái
        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);//Lọc dữ liệu theo trạng thái mà người dùng input
        }//kiểm tra xem người dùng có gửi thông tin trạng thái (status) lên không và giá trị đó có khác rỗng không.
        
        // Lọc theo loại bài kiểm tra
        if ($request->has('test_id') && !empty($request->test_id)) {
            $query->where('test_id', $request->test_id);
        }
//Dòng này kiểm tra xem trong yêu cầu có gửi trường test_id và giá trị của nó không rỗng hay không. Nếu đúng, thì sẽ thực hiện đoạn lệnh bên trong.
//Lọc dữ liệu theo theo test_id mà người dùng input
        $certificates = $query->orderBy('created_at', 'desc')->paginate(10);
//sắp xếp theo ngày tạo mới nhất (created_at giảm dần), và phân trang mỗi trang 10 bản ghi
        $tests = Test::all();
        
        return view('admin.certificates.index', compact('certificates', 'tests'));
    }

    /**
     * Hiển thị form tạo chứng chỉ mới
     */
    public function create()
    {
        $users = User::whereHas('role', function($q) {
            $q->where('name', 'Thuyền viên');
        })->get();
//Dòng này lấy tất cả người dùng (User) mà có vai trò (role) là "Thuyền viên".
        $tests = Test::where('is_active', true)->get();
//Lấy tất cả bài kiểm tra (Test) đang ở trạng thái kích hoạt (is_active = true).
        return view('admin.certificates.create', compact('users', 'tests'));
    }

    /**
     * Lưu chứng chỉ mới
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'test_id' => 'nullable|exists:tests,id',
            'test_attempt_id' => 'nullable|exists:test_attempts,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:issue_date',
            'certificate_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        
        try {
            DB::beginTransaction();
//đảm bảo toàn bộ quá trình tạo chứng chỉ diễn ra an toàn, nếu có lỗi sẽ hoàn tác lại.
            
            // Tạo số chứng chỉ duy nhất
            $certificateNumber = 'CERT-' . date('Y') . '-' . Str::random(8);
            
            $certificate = new Certificate();
            $certificate->user_id = $request->user_id;
            $certificate->test_id = $request->test_id;
            $certificate->test_attempt_id = $request->test_attempt_id;
            $certificate->certificate_number = $certificateNumber;
            $certificate->title = $request->title;
            $certificate->description = $request->description;
            $certificate->issue_date = $request->issue_date;
            $certificate->expiry_date = $request->expiry_date;
            $certificate->status = 'active';
            $certificate->issued_by = auth()->id();
            
            // Xử lý upload file
            if ($request->hasFile('certificate_file')) {
    //kiểm tra xem người dùng có gửi kèm file certificate_file trong yêu cầu hay không. Nếu có thì mới thực hiện phần xử lý file.
                $file = $request->file('certificate_file');
                $fileName = time() . '_' . $file->getClientOriginalName();
    //Tạo tên file mới bằng cách nối thời gian hiện tại với tên gốc của file (để tránh trùng tên).
                $filePath = $file->storeAs('certificates', $fileName, 'public');
    //lưu file được upload vào thư mục certificates trong bộ nhớ public với tên file là $fileName, và trả về đường dẫn lưu file để dùng tiếp
                $certificate->certificate_file = $filePath;
    //gán đường dẫn file đã lưu ($filePath) cho thuộc tính certificate_file của đối tượng chứng chỉ, để lưu thông tin file vào database.
            }
            $certificate->save();
            
            DB::commit();
            return redirect()->route('admin.certificates.index')
                            ->with('success', 'Chứng chỉ đã được tạo thành công!');
                            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Hiển thị thông tin chi tiết chứng chỉ
     */
    public function show($id)
    {
        $certificate = Certificate::with(['user', 'test', 'testAttempt', 'issuer'])->findOrFail($id);
//lấy chứng chỉ theo $id, kèm theo dữ liệu liên quan là người dùng (user), bài kiểm tra (test), lần làm bài kiểm tra (testAttempt) và người cấp (issuer).
        return view('admin.certificates.show', compact('certificate'));
    }

    /**
     * Hiển thị form chỉnh sửa chứng chỉ
     */
    public function edit($id)
    {
        $certificate = Certificate::findOrFail($id);
        $users = User::whereHas('role', function($q) {//kiểm tra mối quan hệ role của User — tức mỗi user có ít nhất một vai trò.
            $q->where('name', 'Thuyền viên');// lọc để chỉ lấy vai trò có tên là "Thuyền viên"
        })->get();
        $tests = Test::all();
        
        // Lấy các bài thi của thuyền viên
        $testAttempts = TestAttempt::where('user_id', $certificate->user_id)//có nghĩa là lấy tất cả các lần làm bài kiểm tra của người dùng đã được cấp chứng chỉ $certificate
                                 ->with('test')// lấy thêm thông tin liên quan từ bảng test (bài kiểm tra) cho mỗi lần làm bài.
                                 ->orderBy('created_at', 'desc')// sắp xếp kết quả theo ngày tạo mới nhất trước
                                 ->get();// lấy tất cả kết quả ra thành một danh sách.
        
        return view('admin.certificates.edit', compact('certificate', 'users', 'tests', 'testAttempts'));
    }

    /**
     * Cập nhật thông tin chứng chỉ
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:issue_date',
            'status' => 'required|in:active,revoked,expired',
            'revocation_reason' => 'required_if:status,revoked|nullable|string',
            'certificate_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        
        try {
            DB::beginTransaction();
            
            $certificate = Certificate::findOrFail($id);
            $certificate->title = $request->title;
            $certificate->description = $request->description;
            $certificate->issue_date = $request->issue_date;
            $certificate->expiry_date = $request->expiry_date;
            $certificate->status = $request->status;
            
            if ($request->status === 'revoked') {
                $certificate->revocation_reason = $request->revocation_reason;
            }
//Nếu trạng thái (status) trong yêu cầu là 'revoked' (bị thu hồi), 
// thì gán lý do thu hồi (revocation_reason) từ dữ liệu người dùng gửi vào cho chứng chỉ.
            // Xử lý upload file
            if ($request->hasFile('certificate_file')) {
                // Xóa file cũ nếu có
                if ($certificate->certificate_file && Storage::disk('public')->exists($certificate->certificate_file)) {
                    Storage::disk('public')->delete($certificate->certificate_file);//xóa file chứng chỉ (certificate_file) khỏi bộ nhớ public của hệ thống lưu trữ.
                }
//kiểm tra:Nếu chứng chỉ có file (certificate_file) vàFile đó tồn tại trong ổ đĩa public của hệ thống lưu trữ (Storage),


                $file = $request->file('certificate_file');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('certificates', $fileName, 'public');
                $certificate->certificate_file = $filePath;
            }
            
            $certificate->save();
            
            DB::commit();
            return redirect()->route('admin.certificates.show', $certificate->id)
                            ->with('success', 'Chứng chỉ đã được cập nhật thành công!');
                            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Xóa chứng chỉ
     */
    public function destroy($id)
    {
        $certificate = Certificate::findOrFail($id);
        
        // Xóa file đính kèm nếu có
        if ($certificate->certificate_file && Storage::disk('public')->exists($certificate->certificate_file)) {
            Storage::disk('public')->delete($certificate->certificate_file);
        }
        
        $certificate->delete();
        
        return redirect()->route('admin.certificates.index')
                        ->with('success', 'Chứng chỉ đã được xóa thành công!');
    }
    
    /**
     * Hiển thị form tạo chứng chỉ từ bài thi
     */
    public function createFromAttempt($attemptId)
    {
        $attempt = TestAttempt::with(['user', 'test'])->findOrFail($attemptId);
//Tôi hiểu là Testattempt lấy thông tin từ user và test qua attemptId.
        
        // Kiểm tra xem bài thi có đạt điểm chuẩn không
        $test = $attempt->test;
        $passingScore = $test->passing_score ?? 50;//Lấy điểm đạt (passing_score) của bài kiểm tra $test, nếu không có giá trị thì mặc định là 50.
        
        if ($attempt->score < $passingScore) {
            return redirect()->back()->with('error', 'Bài thi này không đạt điểm chuẩn để cấp chứng chỉ!');
        }
        
        return view('admin.certificates.create_from_attempt', compact('attempt'));
    }
    
    /**
     * Lưu chứng chỉ từ bài thi
     */
    public function storeFromAttempt(Request $request, $attemptId)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:issue_date',
            'certificate_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        
        try {
            DB::beginTransaction();
            
            $attempt = TestAttempt::with(['user', 'test'])->findOrFail($attemptId);
            
            // Tạo số chứng chỉ duy nhất
            $certificateNumber = 'CERT-' . date('Y') . '-' . Str::random(8);
//Đoạn mã trên tạo một mã chứng chỉ có dạng "CERT-[năm hiện tại]-[chuỗi ngẫu nhiên 8 ký tự]" để đảm bảo tính duy nhất cho mỗi chứng chỉ.
            $certificate = new Certificate();
            $certificate->user_id = $attempt->user_id;
            $certificate->test_id = $attempt->test_id;
            $certificate->test_attempt_id = $attempt->id;
            $certificate->certificate_number = $certificateNumber;
            $certificate->title = $request->title;
            $certificate->description = $request->description;
            $certificate->issue_date = $request->issue_date;
            $certificate->expiry_date = $request->expiry_date;
            $certificate->status = 'active';
            $certificate->issued_by = auth()->id();// Lấy ID của người dùng hiện tại đã đăng nhập vào hệ thống
            
            // Xử lý upload file
            if ($request->hasFile('certificate_file')) {
                $file = $request->file('certificate_file');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('certificates', $fileName, 'public');
                $certificate->certificate_file = $filePath;
            }
            
            $certificate->save();
            
            DB::commit();
            return redirect()->route('admin.certificates.show', $certificate->id)
                            ->with('success', 'Chứng chỉ đã được tạo thành công!');
                            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }
    
    /**
     * Tạo chứng chỉ PDF cho thuyền viên
     */
    public function generatePdf($id)
    {
        $certificate = Certificate::with(['user', 'test', 'issuer'])->findOrFail($id);
        
        $pdf = Pdf::loadView('admin.certificates.pdf', compact('certificate'));
        
        return $pdf->download($certificate->certificate_number . '.pdf');
    }
    
    /**
     * Hiển thị lịch sử bài kiểm tra của thuyền viên
     */
    public function testHistory($userId)
    {
        $user = User::with('thuyenVien')->findOrFail($userId);
        $testAttempts = TestAttempt::where('user_id', $userId)//danh sách lần làm bài kiểm tra của người dùng
                                 ->with(['test', 'certificates'])//kèm theo bài kiểm tra và chứng chỉ
                                 ->orderBy('created_at', 'desc')
                                 ->paginate(15);
        
        return view('admin.certificates.test_history', compact('user', 'testAttempts'));
    }
}
