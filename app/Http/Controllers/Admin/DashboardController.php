<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\Position;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Hiển thị trang dashboard cho admin
     */
    public function index()
    {
        // Lấy số lượng thuyền viên
        $seafarerId = Role::where('name', 'Thuyền viên')->first()->id;//lấy id của role thuyền viên trong bảng role
        $seafarerCount = User::where('role_id', $seafarerId)->count();//đếm role_id trong bảng user
        
        // Lấy số lượng câu hỏi, bài kiểm tra và lượt thi
        $questionCount = Question::count();//Đếm tất cả câu hỏi trong bảng questions và gán vào $questionCount
        $testCount = Test::count();
        $testAttemptCount = TestAttempt::count();
        
        // Lấy danh sách bài kiểm tra gần đây
        $recentTests = Test::with(['position', 'shipType'])
                        ->orderBy('created_at', 'desc')
                        ->take(5)
                        ->get();
        
        // Lấy danh sách lượt thi gần đây
        $recentTestAttempts = TestAttempt::with(['user', 'test'])
                            ->orderBy('created_at', 'desc')
                            ->take(5)
                            ->get();
        
        // Thống kê thuyền viên theo chức danh
        $seafarersByPosition = Position::leftJoin('thuyen_viens', 'positions.id', '=', 'thuyen_viens.position_id')
    //Lấy tất cả vị trí, kèm danh sách thuyền viên tương ứng (nếu có), bằng cách dùng LEFT JOIN.
                            ->select('positions.name', DB::raw('COUNT(thuyen_viens.id) as count'))
                            
    // Lấy tên vị trí + số thuyền viên ở mỗi vị trí.Đếm số thuyền viên thuộc vị trí đó 
                            ->groupBy('positions.id', 'positions.name')
                            ->get();
        
        // Điểm trung bình theo loại bài kiểm tra
        $averageScoresByTest = Test::leftJoin('test_attempts', 'tests.id', '=', 'test_attempts.test_id')
//thực hiện một phép LEFT JOIN giữa bảng tests và bảng test_attempts (bảng các lần thử nghiệm làm bài kiểm tra), dựa trên mối quan hệ giữa tests.id và test_attempts.test_id.
                            ->select('tests.title', DB::raw('AVG(test_attempts.score) as average_score'))
        //lấy tiêu đề bài test và tính số điểm trung bình các lần làm của bài test đó
                            ->groupBy('tests.id', 'tests.title')
                            ->get();
        
        return view('admin.dashboard', compact(
            'seafarerCount', 
            'questionCount', 
            'testCount', 
            'testAttemptCount',
            'recentTests',
            'recentTestAttempts',
            'seafarersByPosition',
            'averageScoresByTest'
        ));
    }
}
