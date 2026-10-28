@switch($roleName)
    @case('admin')Администратор @break
    @case('organizer')Организатор @break
    @case('participant')Участник @break
    @default Роль не назначена
@endswitch
