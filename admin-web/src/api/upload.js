import http from './request'

export function uploadImage(file, scene = 'other') {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('scene', scene)
  return http.post('/upload/image', fd, { timeout: 120000 })
}
